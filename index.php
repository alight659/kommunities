<?php
// error_reporting(E_ALL);
// ini_set('display_errors', 1);
// session_start();

// random display name
if (!isset($_SESSION['display_name'])) {
    $_SESSION['display_name'] = "anon" . rand(1000, 999999);
}
$displayName = $_SESSION['display_name'];

// sqlite
$db = new PDO("sqlite:" . __DIR__ . "/db.sqlite");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// current forum
$forum = $_GET['forum'] ?? "general";
$stmt = $db->prepare("INSERT OR IGNORE INTO forums (name) VALUES (:name)");
$stmt->execute([':name' => $forum]);

// new forum
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_forum'])) {
    $new_forum = trim($_POST['new_forum']);
    if ($new_forum !== '') {
        $stmt = $db->prepare("INSERT OR IGNORE INTO forums (name) VALUES (:name)");
        $stmt->execute([':name' => htmlspecialchars($new_forum)]);
        header("Location: index.php?forum=" . urlencode($new_forum));
        exit;
    }
}

// new post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['content']) && !isset($_POST['comment_post_id'])) {
    $stmt = $db->prepare("INSERT INTO posts (forum_id, author, content) 
        VALUES ((SELECT id FROM forums WHERE name=:forum), :author, :content)");
    $stmt->execute([
        ':forum' => $forum,
        ':author' => $displayName,
        ':content' => htmlspecialchars($_POST['content'])
    ]);
    header("Location: index.php?forum=" . urlencode($forum));
    exit;
}

// new comment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_post_id'], $_POST['comment_content'])) {
    $stmt = $db->prepare("INSERT INTO comments (post_id, parent_id, author, content)
        VALUES (:post_id, :parent_id, :author, :content)");
    $stmt->execute([
        ':post_id' => (int)$_POST['comment_post_id'],
        ':parent_id' => $_POST['parent_id'] ?: null,
        ':author' => $displayName,
        ':content' => htmlspecialchars($_POST['comment_content'])
    ]);
    header("Location: index.php?forum=" . urlencode($forum) . "#post" . $_POST['comment_post_id']);
    exit;
}

// vote
if (isset($_GET['vote'], $_GET['post_id'])) {
    $post_id = (int) $_GET['post_id'];
    $value = ($_GET['vote'] === 'up') ? 1 : -1;

    $stmt = $db->prepare("SELECT value FROM votes WHERE post_id=:pid AND voter=:voter");
    $stmt->execute([':pid'=>$post_id, ':voter'=>$displayName]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $stmt = $db->prepare("UPDATE votes SET value=:val WHERE post_id=:pid AND voter=:voter");
        $stmt->execute([':val'=>$value, ':pid'=>$post_id, ':voter'=>$displayName]);
    } else {
        $stmt = $db->prepare("INSERT INTO votes (post_id, voter, value) VALUES (:pid, :voter, :val)");
        $stmt->execute([':pid'=>$post_id, ':voter'=>$displayName, ':val'=>$value]);
    }

    $stmt = $db->prepare("UPDATE posts SET votes = (SELECT COALESCE(SUM(value),0) FROM votes WHERE post_id=:pid) WHERE id=:pid");
    $stmt->execute([':pid'=>$post_id]);

    header("Location: index.php?forum=" . urlencode($forum));
    exit;
}

// forum
$forums = $db->query("SELECT name FROM forums ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

// posts
$stmt = $db->prepare("SELECT posts.* FROM posts 
    JOIN forums ON posts.forum_id = forums.id
    WHERE forums.name=:forum
    ORDER BY created_at DESC");
$stmt->execute([':forum'=>$forum]);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// comment
function get_comments($db, $post_id, $parent_id = null) {
    $stmt = $db->prepare("SELECT * FROM comments WHERE post_id=:post_id AND parent_id IS :parent ORDER BY created_at ASC");
    $stmt->execute([':post_id'=>$post_id, ':parent'=>$parent_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>


<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Kommunities - k/<?= htmlspecialchars($forum) ?>
    </title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 text-gray-900">

    <header class="bg-white shadow sticky top-0 z-50">
        <div class="max-w-6xl mx-auto flex justify-between items-center p-4 md:p-6">
            <h1 class="text-2xl md:text-3xl font-bold text-blue-600">Kommunities</h1>
            <div class="text-gray-700 text-sm md:text-base">Logged in as <b>
                    <?= htmlspecialchars($displayName) ?>
                </b></div>
        </div>
    </header>

    <div class="max-w-6xl mx-auto p-4 md:p-6 grid grid-cols-1 md:grid-cols-12 gap-6 mt-4">

        <div class="col-span-1 md:col-span-9 space-y-6">
            <!-- forum name -->
            <div class="bg-white shadow rounded-lg p-6">
                <h2 class="text-2xl font-semibold mb-2">k/<?= htmlspecialchars($forum) ?>
                </h2>
            </div>

            <!-- new post -->
            <div class="bg-white shadow rounded-lg p-6">
                <h2 class="text-xl font-semibold mb-3">Create Post</h2>
                <form method="post" class="space-y-3">
                    <textarea name="content" required class="w-full border rounded p-3 focus:ring focus:outline-none"
                        placeholder="What's on your mind?"></textarea>
                    <button type="submit"
                        class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">Post</button>
                </form>
            </div>

            <!-- post -->
            <?php if($posts): ?>
            <?php foreach($posts as $post): ?>
            <div id="post<?= $post['id'] ?>" class="bg-white shadow rounded-lg flex flex-col md:flex-row">
                <!-- voting -->
                <div
                    class="flex md:flex-col flex-row items-center md:items-start p-3 bg-gray-50 rounded-t-lg md:rounded-l-lg md:rounded-t-none space-x-2 md:space-x-0 md:space-y-2">
                    <!-- upvote -->
                    <a href="?forum=<?= urlencode($forum) ?>&vote=up&post_id=<?= $post['id'] ?>"
                        class="flex items-center justify-center w-8 h-8 md:w-10 md:h-10 bg-gray-200 hover:bg-green-500 text-gray-700 hover:text-white rounded cursor-pointer">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6" fill="none"
                            viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                        </svg>
                    </a>

                    <!-- vote count -->
                    <span class="font-bold my-1 text-center">
                        <?= $post['votes'] ?>
                    </span>

                    <!-- downvote -->
                    <a href="?forum=<?= urlencode($forum) ?>&vote=down&post_id=<?= $post['id'] ?>"
                        class="flex items-center justify-center w-8 h-8 md:w-10 md:h-10 bg-gray-200 hover:bg-red-500 text-gray-700 hover:text-white rounded cursor-pointer">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6" fill="none"
                            viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </a>
                </div>

                <!-- content -->
                <div class="flex-1 p-4">
                    <p class="mb-2">
                        <?= nl2br(htmlspecialchars($post['content'])) ?>
                    </p>
                    <p class="text-xs text-gray-500 mb-2">Posted by
                        <?= htmlspecialchars($post['author']) ?> on
                        <?= $post['created_at'] ?>
                    </p>

                    <!-- comments -->
                    <div class="ml-0 md:ml-6 space-y-3">
                        <?php foreach(get_comments($db, $post['id']) as $comment): ?>
                        <div class="bg-gray-50 p-3 rounded">
                            <p class="text-sm mb-1">
                                <?= nl2br(htmlspecialchars($comment['content'])) ?>
                            </p>
                            <p class="text-xs text-gray-500">By
                                <?= htmlspecialchars($comment['author']) ?> on
                                <?= $comment['created_at'] ?>
                            </p>


                            <!-- replies -->
                            <?php foreach(get_comments($db, $post['id'], $comment['id']) as $reply): ?>
                            <div class="bg-gray-100 p-2 rounded ml-4 mt-2">
                                <p class="text-sm mb-1">
                                    <?= nl2br(htmlspecialchars($reply['content'])) ?>
                                </p>
                                <p class="text-xs text-gray-500">By
                                    <?= htmlspecialchars($reply['author']) ?> on
                                    <?= $reply['created_at'] ?>
                                </p>
                            </div>
                            <?php endforeach; ?>


                            <!-- reply form -->
                            <form method="post" class="mt-2 flex space-x-2">
                                <input type="hidden" name="comment_post_id" value="<?= $post['id'] ?>">
                                <input type="hidden" name="parent_id" value="<?= $comment['id'] ?>">
                                <input type="text" name="comment_content" required placeholder="Reply..."
                                    class="flex-1 border rounded p-2 focus:ring focus:outline-none">
                                <button type="submit"
                                    class="bg-blue-500 text-white px-3 py-1 rounded hover:bg-blue-600">Reply</button>
                            </form>
                        </div>
                        <?php endforeach; ?>


                        <!-- comment form -->
                        <form method="post" class="mt-3 flex space-x-2">
                            <input type="hidden" name="comment_post_id" value="<?= $post['id'] ?>">
                            <input type="text" name="comment_content" required placeholder="Add a comment..."
                                class="flex-1 border rounded p-2 focus:ring focus:outline-none">
                            <button type="submit"
                                class="bg-blue-500 text-white px-3 py-1 rounded hover:bg-blue-600">Comment</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <p class="text-gray-500">No posts yet. Be the first!</p>
            <?php endif; ?>
        </div>

        <!-- side menu -->
        <div class="col-span-1 md:col-span-3 space-y-6">
            <div class="bg-white shadow rounded-lg p-4 sticky top-20">
                <h2 class="font-semibold mb-2">Forums</h2>
                <div class="flex flex-col space-y-1">
                    <?php foreach($forums as $f): ?>
                    <a href="index.php?forum=<?= urlencode($f) ?>"
                        class="px-3 py-2 rounded hover:bg-gray-100 <?= $f === $forum ? 'bg-gray-200 font-bold' : '' ?>">k/<?= htmlspecialchars($f) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bg-white shadow rounded-lg p-4 sticky top-72">
                <h2 class="font-semibold mb-2">Create New Forum</h2>
                <form method="post" class="space-y-2">
                    <input type="text" name="new_forum" required placeholder="Forum name"
                        class="w-full border rounded p-2 focus:ring focus:outline-none">
                    <button type="submit"
                        class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600">Create</button>
                </form>
            </div>
        </div>
    </div>
</body>

</html>