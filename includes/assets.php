<?php
/**
 * CTU MindCare — Shared Head Assets
 * ------------------------------------------------------------------
 * Include once inside <head> (after any page-specific <style>).
 * Loads: DM Sans / DM Serif Display, the MindCare design system,
 * Lucide icons, Motion (Framer Motion for the web) and mindcare.js.
 *
 * Optional variables (set BEFORE including):
 *   $mc_title         string   document title (defaults to "CTU MindCare")
 *   $mc_description   string   meta description
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$mc_title       = $mc_title ?? 'CTU MindCare';
$mc_description = $mc_description ?? 'CTU MindCare — Mental health support system for CTU Consolacion students.';
?>
<title><?= clean($mc_title) ?></title>
<meta name="description" content="<?= clean($mc_description) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/mindcare.css">
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<script type="module" src="<?= SITE_URL ?>/assets/js/motion-loader.js"></script>
<script defer src="<?= SITE_URL ?>/assets/js/mindcare.js"></script>
