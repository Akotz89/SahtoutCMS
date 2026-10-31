#!/usr/bin/python3
"""Bounded read-only host sampling; only the atomic telemetry file is written."""
import json, os, pathlib, shutil, socket, subprocess, time
OUT = pathlib.Path('/var/lib/sahtoutcms/metrics/resources.json')
UNITS = ['azerothcore-worldserver.service', 'azerothcore-authserver.service', 'azerothcore-mysql.service', 'httpd.service', 'php-fpm.service']
def cpu():
    return list(map(int, pathlib.Path('/proc/stat').read_text().splitlines()[0].split()[1:9]))
def number(v):
    return int(v) if v and v.isdigit() and int(v) < 2**63 else None
old = {}
try:
    old = json.loads(OUT.read_text())
except (OSError, ValueError):
    pass
a = cpu(); time.sleep(1); b = cpu()
delta = sum(b) - sum(a)
mem = {line.split(':')[0]: int(line.split()[1])*1024 for line in pathlib.Path('/proc/meminfo').read_text().splitlines()}
now = time.time()
data = dict(sampled_at=now, host=socket.gethostname(), cpus=os.cpu_count(), uptime_seconds=float(pathlib.Path('/proc/uptime').read_text().split()[0]), cpu_percent=100*(1-((b[3]-a[3])+(b[4]-a[4]))/delta) if delta else None, load=list(os.getloadavg()), memory=dict(total=mem['MemTotal'], available=mem['MemAvailable'], used=mem['MemTotal']-mem['MemAvailable']), swap=dict(total=mem['SwapTotal'], used=mem['SwapTotal']-mem['SwapFree']), pressure={}, disks=[], services=[])
for kind in ['cpu','memory','io']:
    try:
        data['pressure'][kind] = {line.split()[0]: {k: float(v) for k,v in (s.split('=') for s in line.split()[1:])} for line in pathlib.Path('/proc/pressure',kind).read_text().splitlines()}
    except OSError:
        data['pressure'][kind] = None
for path in ['/','/srv/wow']:
    usage=shutil.disk_usage(path)
    data['disks'].append(dict(path=path,total=usage.total,used=usage.used,free=usage.free))
for unit in UNITS:
    service = dict(unit=unit,state='unknown',memory=None,cpu_percent=None)
    try:
        result=subprocess.run(['/usr/bin/systemctl','show',unit,'--property=ActiveState,MemoryCurrent,CPUUsageNSec,InvocationID,NRestarts'],capture_output=True,text=True,timeout=2,check=True)
        props=dict(line.split('=',1) for line in result.stdout.splitlines() if '=' in line)
        service.update(state=props.get('ActiveState','unknown'),memory=number(props.get('MemoryCurrent')),cpu_ns=number(props.get('CPUUsageNSec')),invocation=props.get('InvocationID'),restarts=number(props.get('NRestarts')))
        prev=next((s for s in old.get('services',[]) if s['unit']==unit),{})
        elapsed=now-old.get('sampled_at',now)
        if 0 < elapsed < 120 and service['invocation'] and service['invocation']==prev.get('invocation') and service['cpu_ns'] is not None and prev.get('cpu_ns') is not None:
            diff=service['cpu_ns']-prev['cpu_ns']
            if diff>=0: service['cpu_percent']=diff/elapsed/1e9*100
    except (subprocess.SubprocessError, OSError):
        pass
    data['services'].append(service)
OUT.parent.mkdir(parents=True,exist_ok=True)
tmp=OUT.with_suffix('.tmp')
tmp.write_text(json.dumps(data,allow_nan=False)); tmp.chmod(0o644); tmp.replace(OUT)
