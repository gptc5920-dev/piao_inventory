<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? 'OSAEITS') ?> - Office Supplies & Equipment</title>
    <link rel="icon" type="image/png" href="<?= isset($base_url) ? htmlspecialchars($base_url) : '' ?>assets/images/piao_logo.png">
    <!-- Bootstrap 4 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <!-- SB Admin 2 -->
    <link href="https://cdn.jsdelivr.net/gh/StartBootstrap/startbootstrap-sb-admin-2@gh-pages/css/sb-admin-2.min.css" rel="stylesheet">
    <!-- Custom -->
    <link href="<?= isset($base_url) ? htmlspecialchars($base_url) : '' ?>assets/css/custom.css" rel="stylesheet">
</head>
<body id="page-top" class="osaeits-app-bg">
    <div id="wrapper">
