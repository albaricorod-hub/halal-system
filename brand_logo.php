<?php
$brandLabel = $brandLabel ?? 'Halal Heritage';
$brandHref = $brandHref ?? 'index.php';
$brandTone = $brandTone ?? 'light';
$brandToneClass = $brandTone === 'dark' ? 'hh-brand-on-dark' : 'hh-brand-on-light';
?>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&display=swap');

    .hh-brand-lockup {
        position: relative;
        z-index: 2;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
        color: #163b32;
        font-family: 'Cormorant Garamond', Georgia, serif;
        font-size: 1.5rem;
        font-weight: 700;
        line-height: 1.1;
        text-decoration: none;
        white-space: nowrap;
    }

    .hh-brand-on-dark { color: #f2b846; }
    .hh-brand-on-light { color: #163b32; }

    .hh-brand-mark {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 46px;
        flex: 0 0 auto;
    }

    .hh-brand-mark img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    .hh-brand-on-dark .hh-brand-mark img {
        filter: brightness(0) invert(1);
    }
</style>
<a href="<?= htmlspecialchars($brandHref, ENT_QUOTES, 'UTF-8') ?>" class="hh-brand-lockup <?= $brandToneClass ?>">
    <span class="hh-brand-mark"><img src="assets/logo.png" alt=""></span>
    <span><?= htmlspecialchars($brandLabel, ENT_QUOTES, 'UTF-8') ?></span>
</a>
<?php unset($brandLabel, $brandHref, $brandTone, $brandToneClass); ?>