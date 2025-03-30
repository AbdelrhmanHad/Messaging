<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php"); // Redirect to login page
    exit();
}

$user_id = $_SESSION['user_id'];

$host = 'localhost';
$dbname = 'messaging_system';
$username = 'root';
$password = '';

$error_message = ''; // Variable to store error message

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}


// Search functionality for users
$search = $_GET['search'] ?? '';

// Fetch users excluding the logged-in user
$users_stmt = $conn->prepare("
    SELECT u.id, u.username, 
    (SELECT message FROM messages 
     WHERE (sender_id = u.id AND receiver_id = :user_id) 
        OR (sender_id = :user_id AND receiver_id = u.id) 
     ORDER BY sent_at DESC LIMIT 1) AS latest_message 
    FROM users u 
    WHERE u.id != :user_id AND u.username LIKE :search 
    ORDER BY u.username");

$users_stmt->execute(['user_id' => $user_id, 'search' => "%$search%"]);
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get messages if a receiver_id is provided
$receiver_id = $_GET['receiver_id'] ?? null;
$messages = [];

if ($receiver_id) {
    $messages_stmt = $conn->prepare("
        SELECT * FROM messages 
        WHERE (sender_id = :user_id AND receiver_id = :receiver_id) 
        OR (sender_id = :receiver_id AND receiver_id = :user_id) 
        ORDER BY sent_at ASC");
    $messages_stmt->execute(['user_id' => $user_id, 'receiver_id' => $receiver_id]);
    $messages = $messages_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle message reporting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_message_id'])) {
    $message_id = $_POST['report_message_id'];
    
    $report_stmt = $conn->prepare("UPDATE messages SET reported = 1 WHERE id = :message_id");
    $report_stmt->execute(['message_id' => $message_id]);

    header('Location: send_message.php?receiver_id=' . $receiver_id);
    exit();
}

// Handle sending a new message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'], $_POST['receiver_id'])) {
    $message = $_POST['message'];
    $receiver_id = $_POST['receiver_id'];

    // Check if the sender is blocked by the receiver
    $block_check_stmt = $conn->prepare("
        SELECT * FROM blocked_users 
        WHERE blocker_id = :receiver_id AND blocked_id = :user_id
    ");
    $block_check_stmt->execute(['receiver_id' => $receiver_id, 'user_id' => $user_id]);

    if ($block_check_stmt->fetch()) {
        $error_message = "You cannot send messages to this user.";
    } else {
        // If not blocked, proceed to send the message
        $send_stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (:sender_id, :receiver_id, :message)");
        $send_stmt->execute(['sender_id' => $user_id, 'receiver_id' => $receiver_id, 'message' => $message]);
        header('Location: send_message.php?receiver_id=' . $receiver_id);
        exit();
    }
}

// Handle block/unblock functionality
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['block_user_id'])) {
    $block_user_id = $_POST['block_user_id'];

    // Check if the user is already blocked
    $check_block_stmt = $conn->prepare("SELECT * FROM blocked_users WHERE blocker_id = :user_id AND blocked_id = :block_user_id");
    $check_block_stmt->execute(['user_id' => $user_id, 'block_user_id' => $block_user_id]);
    $blocked_user = $check_block_stmt->fetch(PDO::FETCH_ASSOC);

    if ($blocked_user) {
        // If the user is blocked, unblock them
        $unblock_stmt = $conn->prepare("DELETE FROM blocked_users WHERE blocker_id = :user_id AND blocked_id = :block_user_id");
        $unblock_stmt->execute(['user_id' => $user_id, 'block_user_id' => $block_user_id]);
    } else {
        // If the user is not blocked, block them
        $block_stmt = $conn->prepare("INSERT INTO blocked_users (blocker_id, blocked_id) VALUES (:user_id, :block_user_id)");
        $block_stmt->execute(['user_id' => $user_id, 'block_user_id' => $block_user_id]);
    }

    header('Location: send_message.php'); // Refresh the page
    exit();
}
// Check if the sender is blocked by the receiver
$block_check_stmt = $conn->prepare("
    SELECT * FROM blocked_users 
    WHERE blocker_id = :receiver_id AND blocked_id = :user_id
");
$block_check_stmt->execute([ 'receiver_id' => $receiver_id, 'user_id' => $user_id ]);

if ($block_check_stmt->fetch()) {
    $error_message = "You cannot send messages to this user.";
}


// Fetch the receiver's username
$receiver_name = '';
if ($receiver_id) {
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = :receiver_id");
    $stmt->bindParam(':receiver_id', $receiver_id, PDO::PARAM_INT);
    $stmt->execute();
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $receiver_name = $row['username'];
    }
}

// Handle message deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_message_id'])) {
    $message_id = $_POST['delete_message_id'];

    // Ensure the current user is the sender of the message
    $check_message_stmt = $conn->prepare("SELECT sender_id FROM messages WHERE id = :message_id");
    $check_message_stmt->execute(['message_id' => $message_id]);
    $message = $check_message_stmt->fetch(PDO::FETCH_ASSOC);

    if ($message && $message['sender_id'] == $user_id) {
        // Delete the message if it exists and belongs to the current user
        $delete_stmt = $conn->prepare("DELETE FROM messages WHERE id = :message_id");
        $delete_stmt->execute(['message_id' => $message_id]);
    }

    // Redirect to the same page after deletion
    header('Location: send_message.php?receiver_id=' . $receiver_id);
    exit();

    // Fetch messages
$messages_stmt = $conn->prepare("
    SELECT * FROM messages 
    WHERE ((sender_id = :user_id AND receiver_id = :receiver_id) 
        OR (sender_id = :receiver_id AND receiver_id = :user_id))
    AND NOT EXISTS (
        SELECT 1 FROM blocked_users 
        WHERE (blocker_id = :user_id AND blocked_id = :receiver_id) 
        OR (blocker_id = :receiver_id AND blocked_id = :user_id)
    )
    ORDER BY sent_at ASC
");
$messages_stmt->execute(['user_id' => $user_id, 'receiver_id' => $receiver_id]);

$messages = $messages_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($messages as $message) {
    echo "<p>{$message['sender_id']}: {$message['message']}</p>";
}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Message</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.0/dist/css/bootstrap.min.css" rel="stylesheet">

</head>
<body>
<div class="container mt-4">
    <div class="row">
        <!-- Users List Section -->
        <div class="col-md-4">
            <h4>Users</h4>
            <form method="GET" class="mb-3">
                <input type="text" name="search" class="form-control" placeholder="Search users..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary mt-2">Search</button>
            </form>
            <ul class="list-group">
                <?php foreach ($users as $user): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <a href="send_message.php?receiver_id=<?php echo $user['id']; ?>">
                            <?php echo htmlspecialchars($user['username']); ?>
                        </a>
                        <small class="text-muted">
                            <?php echo htmlspecialchars($user['latest_message'] ?? 'No messages yet'); ?>
                        </small>
                        
                        <!-- Block/Unblock Button -->
                        <?php
                            // Check if the user is blocked
                            $is_blocked = false;
                            $check_block_stmt = $conn->prepare("SELECT * FROM blocked_users WHERE blocker_id = :user_id AND blocked_id = :block_user_id");
                            $check_block_stmt->execute(['user_id' => $user_id, 'block_user_id' => $user['id']]);
                            if ($check_block_stmt->fetch(PDO::FETCH_ASSOC)) {
                                $is_blocked = true;
                            }
                        ?>
                        <form method="POST" action="send_message.php" style="display:inline;">
                            <input type="hidden" name="block_user_id" value="<?php echo $user['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-sm">
                                <?php echo ($is_blocked) ? 'Unblock' : 'Block'; ?>
                            </button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Message Section -->
        <div class="col-md-8">
            <h4>Conversation with <?php echo htmlspecialchars($receiver_name); ?></h4>

            <!-- Display error message -->
            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>


            <div id="message-box" class="border p-3" style="height: 300px; overflow-y: auto;">
    <?php if ($messages): ?>
        <?php foreach ($messages as $message): ?>
            <div class="d-flex <?php echo ($message['sender_id'] == $user_id) ? 'justify-content-end' : 'justify-content-start'; ?>">
                <div class="p-2 mb-2 rounded" style="max-width: 70%; <?php echo ($message['sender_id'] == $user_id) ? 'background-color: #007bff; color: white; text-align: right;' : 'background-color: #f1f1f1; text-align: left;'; ?>">
                    <strong><?php echo ($message['sender_id'] == $user_id) ? 'You' : htmlspecialchars($receiver_name); ?>:</strong>
                    <p><?php echo htmlspecialchars($message['message']); ?></p>

                    <!-- Report Button (for received messages) -->
                    <?php if ($message['sender_id'] != $user_id && !$message['reported']): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="report_message_id" value="<?php echo $message['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Report Abusive Message</button>
                        </form>

                         <?php elseif ($message['reported'] && $message['sender_id'] != $user_id): ?>
                    <span class="text-danger">[Reported]</span>
                <?php endif; ?>

                    <!-- Delete Button (only for sent messages) -->
                    <?php if ($message['sender_id'] == $user_id): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="delete_message_id" value="<?php echo $message['id']; ?>">
                            <button type="submit" class="btn btn-warning btn-sm">Delete</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

            <!-- Message Form -->
            <form method="POST" id="message-form" class="mt-3">
                <input type="hidden" name="receiver_id" value="<?php echo htmlspecialchars($receiver_id); ?>">
                <textarea name="message" class="form-control" rows="3" required></textarea>
                <button type="submit" class="btn btn-primary mt-2">Send</button>
            </form>
        </div>
    </div>
</div>

<script>
    // Auto-scroll to the latest message
    var chatBox = document.getElementById("message-box");
    chatBox.scrollTop = chatBox.scrollHeight;
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
