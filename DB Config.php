<?php
// Database configuration constants
define('DB_HOST', 'localhost');
define('DB_USER', 'photonim_user');
define('DB_PASS', 'Pouyan@8@Bazhozi');
define('DB_NAME', 'photonim_db');

/**
 * Establishes a connection to the database using PDO.
 * 
 * @return PDO|null Returns the PDO connection object on success, null on failure.
 */
function getDatabaseConnection() {
    try {
        // PDO options for better security and performance
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Return results as associative arrays
            PDO::ATTR_EMULATE_PREPARES   => false,                  // Use real prepared statements
        ];

        // Create PDO connection
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $conn = new PDO($dsn, DB_USER, DB_PASS, $options);

        return $conn;
    } catch (PDOException $e) {
        // Log the error (in a production environment, use a proper logging mechanism)
        error_log("Database connection failed: " . $e->getMessage(), 0);
        
        // Display a user-friendly message and stop execution
        echo "<p style='color: red; text-align: center;'>خطا در اتصال به دیتابیس: " . htmlspecialchars($e->getMessage()) . "</p>";
        exit; // Use exit instead of die() for consistency
        
        return null; // Return null on failure (though this line won't execute due to exit)
    }
}

// Establish database connection
$conn = getDatabaseConnection();

// Ensure connection was successful before proceeding
if (!$conn) {
    exit; // Redundant due to exit in catch, but kept for clarity
}

// Further SQL instructions can follow here if needed
// ...
?>