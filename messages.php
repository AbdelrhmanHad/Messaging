<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["error" => true, "message" => "Please log in to access this page."]);
    exit();
}

$user_id = $_SESSION['user_id'];

$host = 'localhost';
$dbname = 'messaging_system';
$username = 'root';
$password = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

if (isset($_GET['receiver_id'])) {
    $receiver_id = $_GET['receiver_id'];

    // Update the message status to "read"
    $stmt = $conn->prepare("UPDATE messages SET status='read' WHERE receiver_id = :user_id AND sender_id = :receiver_id");
    $stmt->execute(['user_id' => $user_id, 'receiver_id' => $receiver_id]);

    // Fetch the conversation between the logged-in user and the receiver
    $stmt = $conn->prepare("SELECT * FROM messages WHERE (sender_id = :user_id AND receiver_id = :receiver_id) OR (sender_id = :receiver_id AND receiver_id = :user_id) ORDER BY sent_at ASC");
    $stmt->execute(['user_id' => $user_id, 'receiver_id' => $receiver_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Return messages as JSON
    echo json_encode(["success" => true, "messages" => $messages]);
}
?>