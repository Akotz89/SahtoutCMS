<?php
require_once __DIR__ . '/paths.php';

if (!defined('ALLOWED_ACCESS')) {
    if (file_exists($project_root . 'languages/language.php')) {
        require_once $project_root . 'languages/language.php';
    }
    header('HTTP/1.1 403 Forbidden');
    exit(function_exists('translate') ? translate('error_direct_access', 'Direct access to this file is not allowed.') : 'Direct access to this file is not allowed.');
}

// Load settings (if exists)
if (file_exists($project_root . 'includes/config.settings.php')) {
    require_once $project_root . 'includes/config.settings.php';
}
?>
<link rel="stylesheet" href="<?php echo $base_path; ?>node_modules/@fortawesome/fontawesome-free/css/all.min.css">

<style>
    /* ============ FOOTER THEME VARIABLES ============ */
    :root {
        --gold-primary: #f2cf5b;
        --gold-dark: #c9a227;
        --gold-deep: #8a6a14;
    }

    /* ============ FOOTER CONTAINER ============ */
    footer.wow-footer {
        background: linear-gradient(180deg, rgba(5,7,11,0.95) 0%, rgba(10,14,22,0.98) 100%);
        border-top: 1px solid rgba(201,162,39,.25);
        box-shadow: 0 -8px 24px rgba(0,0,0,.6);
        position: relative;
        margin-top: 4rem;
    }

    footer.wow-footer::before {
        content: '';
        position: absolute;
        top: 0; left: 50%; transform: translateX(-50%);
        width: 300px; height: 2px;
        background: linear-gradient(90deg, transparent, var(--gold-primary), transparent);
        box-shadow: 0 0 15px var(--gold-primary);
    }

    /* ============ FOOTER LINKS ============ */
    .footer-nav-link {
        color: #9ca3af;
        transition: all 0.3s ease;
        font-weight: 600;
        font-size: 0.875rem;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        text-decoration: none;
    }
    .footer-nav-link:hover {
        color: var(--gold-primary);
        text-shadow: 0 0 8px rgba(242,207,82,.4);
    }

    /* ============ BACK TO TOP BUTTON (LEFT SIDE) ============ */
    #backToTop {
        position: fixed;
        bottom: 2rem; left: 2rem;
        width: 48px; height: 48px;
        background: linear-gradient(180deg, #f6d478 0%, var(--gold-dark) 48%, var(--gold-deep) 100%);
        color: #1a1200;
        border: none;
        font-size: 1.2rem;
        font-weight: 900;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px);
        box-shadow: 0 8px 20px rgba(0,0,0,.6), inset 0 0 0 1px rgba(255,255,255,.3);
        opacity: 0;
        pointer-events: none;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 999;
    }
    #backToTop:hover {
        transform: translateY(-4px) scale(1.05);
        box-shadow: 0 12px 24px rgba(242,207,82,.4), inset 0 0 0 1px rgba(255,255,255,.5);
        filter: drop-shadow(0 0 12px rgba(242,207,82,.5));
    }
    /* Support common JS class names for visibility toggling */
    #backToTop.show, #backToTop.active, #backToTop.visible, #backToTop.op-100 {
        opacity: 1;
        pointer-events: auto;
    }

    @media (max-width: 768px) {
        #backToTop {
            bottom: 1.5rem; left: 1.5rem;
            width: 42px; height: 42px;
            font-size: 1rem;
        }
    }
</style>

<footer class="wow-footer">
    <div class="max-w-[1600px] mx-auto px-4 md:px-8 py-8 md:py-12">
        <div class="flex flex-col md:flex-row items-center justify-between gap-4 text-center md:text-left">
            <div class="flex items-center gap-3">
                <img src="<?php echo htmlspecialchars($base_path . ltrim($site_logo ?? 'img/logo.png', '/')); ?>"
                     alt="Playerbots" class="h-12 rounded">
                <div>
                    <p class="text-[#f2cf5b] font-bold">Playerbots Account Management</p>
                    <p class="text-gray-500 text-xs">Private LAN operator console</p>
                </div>
            </div>
            <a href="<?php echo $base_path; ?>admin/users" class="footer-nav-link">User Management</a>
        </div>
    </div>
</footer>

<!-- Back to Top Button -->
<button id="backToTop" title="<?php echo htmlspecialchars(translate('back_to_top', 'Back to Top')); ?>">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- Back to Top Script -->
<script src="<?php echo $base_path; ?>assets/js/includes/footer.js"></script>
