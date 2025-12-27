<?php

class AuthMiddleware {
    
    // Check if user is logged in
    public static function requireAuth() {
        if (!isset($_SESSION['user_id'])) {
            jsonResponse(false, 'پێویستە لۆگین بکەیت', null, 401);
        }
        
        return [
            'user_id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role']
        ];
    }
    
    // Check if user is admin
    public static function requireAdmin() {
        $user = self::requireAuth();
        
        if ($user['role'] !== 'admin') {
            jsonResponse(false, 'تەنها Admin دەتوانێت ئەم کردارە ئەنجام بدات', null, 403);
        }
        
        return $user;
    }
    
    // Get current user (nullable)
    public static function getCurrentUser() {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        
        return [
            'user_id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role']
        ];
    }
}
