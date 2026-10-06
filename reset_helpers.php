<?php
/**
 * Shared helpers for the password-reset flow.
 * Put this file next to send_reset_code.php, verify_reset_code.php and reset_password.php.
 */

const RESET_MAX_ATTEMPTS = 5;

/** Creates the table if needed and adds the `attempts` column to an existing table. */
function ensure_reset_table(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        code VARCHAR(10) NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email_code (email, code)
    )");

    // Table created by the old version of the script? Add the missing column once.
    $res = $conn->query("SHOW COLUMNS FROM password_resets LIKE 'attempts'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE password_resets ADD COLUMN attempts INT NOT NULL DEFAULT 0");
    }
}

/**
 * Checks the code for this email.
 * Returns [true, ''] or [false, 'message for the user'].
 * Wrong guesses are counted; after RESET_MAX_ATTEMPTS the code is destroyed.
 */
function check_reset_code(mysqli $conn, string $email, string $code): array
{
    $invalid = [false, "Invalid or expired verification code."];

    $stmt = $conn->prepare(
        "SELECT id, code, attempts FROM password_resets
         WHERE email = ? AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return $invalid;
    }

    if ((int)$row['attempts'] >= RESET_MAX_ATTEMPTS) {
        $del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
        $del->bind_param("s", $email);
        $del->execute();
        $del->close();
        return [false, "Too many wrong attempts. Please request a new code."];
    }

    if (!hash_equals((string)$row['code'], $code)) {
        $upd = $conn->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?");
        $upd->bind_param("i", $row['id']);
        $upd->execute();
        $upd->close();
        return $invalid;
    }

    return [true, ''];
}