<?php
// src/Core/Http.php
// Lightweight PRG helpers: flash messages + 303 redirect

function ensure_session_started() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function flash_add($type, $message) {
    ensure_session_started();
    if (!isset($_SESSION['__flash'])) {
        $_SESSION['__flash'] = [];
    }
    $type = in_array($type, ['success', 'error', 'warning', 'info']) ? $type : 'info';
    $_SESSION['__flash'][] = [
        'type' => $type,
        'message' => $message,
        'time' => time()
    ];
}

function flash_consume_all() {
    ensure_session_started();
    $msgs = $_SESSION['__flash'] ?? [];
    unset($_SESSION['__flash']); // use-once
    return $msgs;
}

function redirect_303($url) {
    // Always redirect to a GET page with 303 to avoid form resubmission
    header('Location: ' . $url, true, 303);
    exit;
}

