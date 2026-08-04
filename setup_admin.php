<?php
require_once 'includes/config.php';

$db = getDB();

// Check if system_admins table exists
try {
    $stmt = $db->query("SELECT 1 FROM system_admins LIMIT 1");
    echo "✓ system_admins table exists<br>";
} catch (Exception $e) {
    die("✗ Error: system_admins table not found. Make sure you've imported the database schema.<br>" . $e->getMessage());
}

// Create default admin account
$email = 'admin@ctumindcare.local';
$password = 'Admin@123456'; // Change this to a secure password
$hashed_password = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

try {
    // Delete existing admin if it exists (for testing)
    $db->prepare("DELETE FROM system_admins WHERE email = ?")->execute([$email]);
    
    // Insert new admin account
    $stmt = $db->prepare("
        INSERT INTO system_admins (first_name, last_name, email, password, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
    ");
    
    $stmt->execute([
        'System',
        'Admin',
        $email,
        $hashed_password,
        1
    ]);
    
    echo "✓ Admin account created successfully!<br><br>";
    echo "<strong>Login Credentials:</strong><br>";
    echo "Email: <code>$email</code><br>";
    echo "Password: <code>$password</code><br>";
    echo "Role: <code>System Admin</code><br><br>";
    echo "<a href='auth/login.php'>Go to Login Page</a>";
    
} catch (Exception $e) {
    die("✗ Error creating admin account: " . $e->getMessage());
}
?>
