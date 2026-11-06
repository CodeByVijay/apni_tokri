<?php

declare(strict_types=1);

/** @var string $pageTitle */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= Security::escape(Csrf::token()) ?>">
    <title><?= Security::escape($pageTitle) ?> — Admin · Veggie Tokri</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: { green: '#245C3A', yellow: '#F5A623', cream: '#FFF8E9', charcoal: '#292D29' }
                    },
                    fontFamily: {
                        sans: ['DM Sans', 'system-ui', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <link rel="stylesheet" href="<?= Security::escape(asset_url('assets/css/apni-tokri.css')) ?>">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
</head>
<body class="at-body  text-brand-charcoal min-h-screen">
