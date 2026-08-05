<?php
/**
 * Shared <head> includes — Tailwind CDN + Config + Fonts + App CSS
 * Include this in every standalone page (pages not using navbar.php)
 */
?>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Sarabun:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap">
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#0058be",
                        "primary-container": "#2170e4",
                        "on-primary": "#ffffff",
                        "on-primary-container": "#fefcff",
                        "secondary": "#006c49",
                        "secondary-container": "#6cf8bb",
                        "secondary-fixed-dim": "#4edea3",
                        "on-secondary": "#ffffff",
                        "on-secondary-container": "#00714d",
                        "tertiary": "#924700",
                        "tertiary-container": "#b75b00",
                        "tertiary-fixed-dim": "#ffb786",
                        "on-tertiary": "#ffffff",
                        "error": "#ba1a1a",
                        "error-container": "#ffdad6",
                        "on-error": "#ffffff",
                        "on-error-container": "#93000a",
                        "surface": "#f9f9ff",
                        "surface-dim": "#cfdaf2",
                        "surface-container-lowest": "#ffffff",
                        "surface-container-low": "#f0f3ff",
                        "surface-container": "#e7eeff",
                        "surface-container-high": "#dee8ff",
                        "surface-container-highest": "#d8e3fb",
                        "on-surface": "#111c2d",
                        "on-surface-variant": "#424754",
                        "outline": "#727785",
                        "outline-variant": "#c2c6d6",
                        "inverse-surface": "#263143",
                        "inverse-on-surface": "#ecf1ff",
                        "background": "#f9f9ff",
                        "on-background": "#111c2d",
                        "primary-fixed": "#d8e2ff",
                    },
                    fontFamily: {
                        sans: ["Inter", "Sarabun", "sans-serif"],
                    },
                }
            }
        }
    </script>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
