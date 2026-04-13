<?php
error_reporting(E_ERROR | E_PARSE);
session_start();


// sqlite
$db = new PDO("sqlite:" . __DIR__ . "/db.sqlite");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode=WAL");

$db->exec("CREATE TABLE IF NOT EXISTS users (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    token        TEXT UNIQUE NOT NULL,
    display_name TEXT NOT NULL,
    bio          TEXT DEFAULT '',
    avatar_data  BLOB DEFAULT NULL,
    avatar_mime  TEXT DEFAULT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_seen    DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS forums (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT UNIQUE,
    admin_id   INTEGER DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    forum_id   INTEGER,
    author     TEXT,
    user_id    INTEGER,
    content    TEXT,
    image_data BLOB DEFAULT NULL,
    image_mime TEXT DEFAULT NULL,
    votes      INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS votes (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER,
    voter   TEXT,
    value   INTEGER,
    UNIQUE(post_id, voter)
)");
$db->exec("CREATE TABLE IF NOT EXISTS comments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    INTEGER,
    parent_id  INTEGER DEFAULT NULL,
    author     TEXT,
    user_id    INTEGER,
    content    TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");


// Migrations
$cols = $db->query("PRAGMA table_info(posts)")->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_column($cols, 'name');
if (!in_array('user_id',    $colNames)) $db->exec("ALTER TABLE posts ADD COLUMN user_id INTEGER DEFAULT NULL");
if (!in_array('image_data', $colNames)) $db->exec("ALTER TABLE posts ADD COLUMN image_data BLOB DEFAULT NULL");
if (!in_array('image_mime', $colNames)) $db->exec("ALTER TABLE posts ADD COLUMN image_mime TEXT DEFAULT NULL");

$cols2 = $db->query("PRAGMA table_info(forums)")->fetchAll(PDO::FETCH_ASSOC);
$col2Names = array_column($cols2, 'name');
if (!in_array('admin_id',   $col2Names)) $db->exec("ALTER TABLE forums ADD COLUMN admin_id INTEGER DEFAULT NULL");
if (!in_array('created_at', $col2Names)) $db->exec("ALTER TABLE forums ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");

$cols3 = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
$col3Names = array_column($cols3, 'name');
if (!in_array('bio',         $col3Names)) $db->exec("ALTER TABLE users ADD COLUMN bio TEXT DEFAULT ''");
if (!in_array('avatar_data', $col3Names)) $db->exec("ALTER TABLE users ADD COLUMN avatar_data BLOB DEFAULT NULL");
if (!in_array('avatar_mime', $col3Names)) $db->exec("ALTER TABLE users ADD COLUMN avatar_mime TEXT DEFAULT NULL");

$cols4 = $db->query("PRAGMA table_info(comments)")->fetchAll(PDO::FETCH_ASSOC);
$col4Names = array_column($cols4, 'name');
if (!in_array('user_id', $col4Names)) $db->exec("ALTER TABLE comments ADD COLUMN user_id INTEGER DEFAULT NULL");


$hasUnique = false;
foreach ($db->query("PRAGMA index_list(votes)") as $idx) {
    if ($idx['unique']) { $hasUnique = true; break; }
}
if (!$hasUnique) {
    $db->exec("BEGIN;
        CREATE TABLE votes_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER, voter TEXT, value INTEGER,
            UNIQUE(post_id, voter)
        );
        INSERT OR IGNORE INTO votes_new (post_id, voter, value)
            SELECT post_id, voter, value FROM votes GROUP BY post_id, voter;
        DROP TABLE votes;
        ALTER TABLE votes_new RENAME TO votes;
        COMMIT;");
}


//  Helpers
function generate_token(): string {
    $raw = bin2hex(random_bytes(8));
    return strtoupper(implode('-', str_split($raw, 4)));
}
function generate_username(): string {
    $adj  = ['swift','quiet','bright','wild','cool','lucky','brave','calm','dark','keen',
             'bold','fuzzy','silver','crimson','azure','golden','cosmic','neon','rusty','jade'];
    $noun = ['fox','owl','pine','wolf','hawk','river','stone','blade','echo','frost',
             'spark','comet','raven','lotus','dune','cedar','lynx','vapor','quill','tide'];
    return $adj[array_rand($adj)] . '_' . $noun[array_rand($noun)] . rand(10,99);
}
function user_by_token(PDO $db, string $token): ?array {
    $s = $db->prepare("SELECT * FROM users WHERE token=:t");
    $s->execute([':t' => strtoupper(trim($token))]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
function touch_user(PDO $db, string $token): void {
    $db->prepare("UPDATE users SET last_seen=CURRENT_TIMESTAMP WHERE token=:t")->execute([':t' => $token]);
}
function create_user(PDO $db): array {
    $token = generate_token(); $name = generate_username();
    $db->prepare("INSERT INTO users (token, display_name) VALUES (:t, :n)")->execute([':t' => $token, ':n' => $name]);
    return ['token' => $token, 'display_name' => $name, 'id' => (int)$db->lastInsertId()];
}
function get_comments(PDO $db, int $post_id, ?int $parent_id = null): array {
    $s = $db->prepare("SELECT * FROM comments WHERE post_id=:pid AND parent_id IS :par ORDER BY created_at ASC");
    $s->execute([':pid' => $post_id, ':par' => $parent_id]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
function forum_name_valid(string $name): bool {
    return $name !== '' && strlen($name) <= 60 && preg_match('/^[\w\-]+$/', $name);
}
function is_forum_admin(PDO $db, string $forum_name, int $user_id): bool {
    $s = $db->prepare("SELECT admin_id FROM forums WHERE name=:n");
    $s->execute([':n' => $forum_name]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r && (int)$r['admin_id'] === $user_id;
}


// Image Validator and EXIF Stripper
function validate_image(array $file): array {
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload error: ' . $file['error']);
    if ($file['size'] > 2 * 1024 * 1024)  throw new RuntimeException('Image must be 2 MB or smaller');
    $raw = file_get_contents($file['tmp_name']);
    if ($raw === false) throw new RuntimeException('Cannot read uploaded file');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->buffer($raw);
    if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true))
        throw new RuntimeException('Only JPEG, PNG, GIF and WebP are allowed');

    if (function_exists('imagecreatefromstring')) {
        $img = imagecreatefromstring($raw);
        if (!$img) throw new RuntimeException('Invalid or corrupt image data');
        ob_start();
        match($mime) {
            'image/jpeg' => imagejpeg($img, null, 85),
            'image/png'  => imagepng($img,  null, 6),
            'image/gif'  => imagegif($img),
            'image/webp' => imagewebp($img, null, 85),
        };
        $clean = ob_get_clean();
        imagedestroy($img);
        return ['data' => $clean, 'mime' => $mime];
    }

    $sizeInfo = @getimagesizefromstring($raw);
    if ($sizeInfo === false) throw new RuntimeException('Invalid or corrupt image data');
    return ['data' => $raw, 'mime' => $mime];
}


//  CSRF
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function csrf_verify(): void {
    $t = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $t)) {
        http_response_code(403); die(json_encode(['error' => 'Invalid CSRF token']));
    }
}


//  Identity Resolution
$currentUser = null; $authError = null;
$bearerToken = null;
$authHeader  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) $bearerToken = trim($m[1]);
if (!$bearerToken && isset($_GET['token'])) $bearerToken = trim($_GET['token']);
if ($bearerToken) { $currentUser = user_by_token($db, $bearerToken); if ($currentUser) touch_user($db, $currentUser['token']); }
if (!$currentUser && !empty($_SESSION['user_token'])) {
    $currentUser = user_by_token($db, $_SESSION['user_token']);
    if ($currentUser) touch_user($db, $currentUser['token']); else unset($_SESSION['user_token']);
}
if (!$currentUser && !empty($_COOKIE['user_token'])) {
    $currentUser = user_by_token($db, $_COOKIE['user_token']);
    if ($currentUser) { $_SESSION['user_token'] = $currentUser['token']; touch_user($db, $currentUser['token']); }
    else setcookie('user_token', '', time() - 1, '/', '', false, true);
}
$displayName = $currentUser['display_name'] ?? '';


//  Image Serving
if (isset($_GET['img'])) {
    $type = $_GET['img']; $id = (int)($_GET['id'] ?? 0);
    if ($type === 'post' && $id) {
        $r = $db->prepare("SELECT image_data,image_mime FROM posts WHERE id=:id AND image_data IS NOT NULL");
        $r->execute([':id' => $id]); $row = $r->fetch(PDO::FETCH_ASSOC);
        if ($row) { header('Content-Type: '.$row['image_mime']); header('Cache-Control: public,max-age=86400'); header('X-Content-Type-Options: nosniff'); echo $row['image_data']; exit; }
    }
    if ($type === 'avatar' && $id) {
        $r = $db->prepare("SELECT avatar_data,avatar_mime FROM users WHERE id=:id AND avatar_data IS NOT NULL");
        $r->execute([':id' => $id]); $row = $r->fetch(PDO::FETCH_ASSOC);
        if ($row) { header('Content-Type: '.$row['avatar_mime']); header('Cache-Control: public,max-age=86400'); header('X-Content-Type-Options: nosniff'); echo $row['avatar_data']; exit; }
    }
    http_response_code(404); exit;
}


//  API Layer
$isApi = isset($_GET['api']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
if ($isApi) {
    header('Content-Type: application/json'); header('X-Content-Type-Options: nosniff');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($action === 'generate_token') {
        $user = create_user($db);
        echo json_encode(['ok'=>true,'token'=>$user['token'],'display_name'=>$user['display_name'],'note'=>'Save your token — it cannot be recovered.']); exit;
    }
    if ($action === 'login') {
        $t = trim($input['token'] ?? $_GET['token'] ?? '');
        if (!$t) { http_response_code(400); echo json_encode(['error'=>'token required']); exit; }
        $user = user_by_token($db, $t);
        if (!$user) { http_response_code(401); echo json_encode(['error'=>'Invalid token']); exit; }
        touch_user($db, $user['token']);
        echo json_encode(['ok'=>true,'display_name'=>$user['display_name'],'token'=>$user['token']]); exit;
    }
    $publicReadActions = ['forums','posts','comments','user_profile'];
    if (!in_array($action, $publicReadActions, true) && !$currentUser) {
        http_response_code(401);
        echo json_encode(['error'=>'Unauthenticated. Send: Authorization: Bearer YOUR-TOKEN',
                          'help'=>['generate'=>'POST ?api=1&action=generate_token','login'=>'POST ?api=1&action=login body:{token}']]); exit;
    }
    switch ($action) {
        case 'me':
            echo json_encode(['display_name'=>$currentUser['display_name'],'token'=>$currentUser['token'],
                              'bio'=>$currentUser['bio'],'created_at'=>$currentUser['created_at'],'last_seen'=>$currentUser['last_seen']]); break;
        case 'rename':
            $name = preg_replace('/[^a-zA-Z0-9_\-]/','',trim($input['display_name'] ?? ''));
            if ($name===''||strlen($name)>30) { http_response_code(400); echo json_encode(['error'=>'display_name must be 1-30 alphanumeric/_/- chars']); break; }
            $db->prepare("UPDATE users SET display_name=:n WHERE token=:t")->execute([':n'=>$name,':t'=>$currentUser['token']]);
            echo json_encode(['ok'=>true,'display_name'=>$name]); break;
        case 'csrf': echo json_encode(['csrf_token'=>csrf_token(),'display_name'=>$displayName]); break;
        case 'forums':
            echo json_encode(['forums'=>$db->query("SELECT name FROM forums ORDER BY name")->fetchAll(PDO::FETCH_COLUMN)]); break;
        case 'create_forum':
            $name = trim($input['name'] ?? '');
            if (!forum_name_valid($name)) { http_response_code(400); echo json_encode(['error'=>'Invalid forum name']); break; }
            $db->prepare("INSERT OR IGNORE INTO forums (name,admin_id) VALUES (:n,:a)")->execute([':n'=>$name,':a'=>(int)$currentUser['id']]);
            echo json_encode(['ok'=>true,'forum'=>$name]); break;
        case 'posts':
            $fn=$_GET['forum']??'general'; $lim=min((int)($_GET['limit']??20),100); $off=max((int)($_GET['offset']??0),0);
            $s=$db->prepare("SELECT id,forum_id,author,user_id,content,votes,created_at,CASE WHEN image_data IS NOT NULL THEN 1 ELSE 0 END AS has_image FROM posts JOIN forums ON posts.forum_id=forums.id WHERE forums.name=:f ORDER BY created_at DESC LIMIT :l OFFSET :o");
            $s->bindValue(':f',$fn); $s->bindValue(':l',$lim,PDO::PARAM_INT); $s->bindValue(':o',$off,PDO::PARAM_INT); $s->execute();
            echo json_encode(['posts'=>$s->fetchAll(PDO::FETCH_ASSOC),'limit'=>$lim,'offset'=>$off]); break;
        case 'create_post':
            $fn=trim($input['forum']??''); $ct=trim($input['content']??'');
            if (!$fn||!$ct) { http_response_code(400); echo json_encode(['error'=>'forum and content required']); break; }
            if (strlen($ct)>10000) { http_response_code(400); echo json_encode(['error'=>'Content too long']); break; }
            $db->prepare("INSERT OR IGNORE INTO forums (name,admin_id) VALUES (:n,:a)")->execute([':n'=>$fn,':a'=>(int)$currentUser['id']]);
            $db->prepare("INSERT INTO posts (forum_id,author,user_id,content) VALUES ((SELECT id FROM forums WHERE name=:f),:a,:uid,:c)")
               ->execute([':f'=>$fn,':a'=>$currentUser['display_name'],':uid'=>(int)$currentUser['id'],':c'=>$ct]);
            echo json_encode(['ok'=>true,'post_id'=>(int)$db->lastInsertId()]); break;
        case 'delete_post':
            $pid=(int)($input['post_id']??0);
            if (!$pid) { http_response_code(400); echo json_encode(['error'=>'post_id required']); break; }
            $r=$db->prepare("SELECT p.user_id,f.name AS forum_name FROM posts p JOIN forums f ON p.forum_id=f.id WHERE p.id=:id"); $r->execute([':id'=>$pid]); $p=$r->fetch(PDO::FETCH_ASSOC);
            if (!$p) { http_response_code(404); echo json_encode(['error'=>'Post not found']); break; }
            if ((int)$p['user_id']!==(int)$currentUser['id'] && !is_forum_admin($db,$p['forum_name'],(int)$currentUser['id'])) { http_response_code(403); echo json_encode(['error'=>'Forbidden']); break; }
            $db->prepare("DELETE FROM comments WHERE post_id=:id")->execute([':id'=>$pid]);
            $db->prepare("DELETE FROM votes WHERE post_id=:id")->execute([':id'=>$pid]);
            $db->prepare("DELETE FROM posts WHERE id=:id")->execute([':id'=>$pid]);
            echo json_encode(['ok'=>true]); break;
        case 'user_profile':
            $uid=(int)($_GET['uid']??0);
            if (!$uid) { http_response_code(400); echo json_encode(['error'=>'uid required']); break; }
            $pu=$db->prepare("SELECT id,display_name,bio,avatar_data,created_at,last_seen FROM users WHERE id=:id");
            $pu->execute([':id'=>$uid]); $pur=$pu->fetch(PDO::FETCH_ASSOC);
            if (!$pur) { http_response_code(404); echo json_encode(['error'=>'User not found']); break; }
            $puf=$db->prepare("SELECT name FROM forums WHERE admin_id=:uid ORDER BY name");
            $puf->execute([':uid'=>$uid]); $purForums=$puf->fetchAll(PDO::FETCH_COLUMN);
            $pup=$db->prepare("SELECT p.id,p.content,p.votes,p.created_at,f.name AS forum_name FROM posts p JOIN forums f ON p.forum_id=f.id WHERE p.user_id=:uid ORDER BY p.created_at DESC LIMIT 20");
            $pup->execute([':uid'=>$uid]); $purPosts=$pup->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['user'=>['id'=>(int)$pur['id'],'display_name'=>$pur['display_name'],'bio'=>$pur['bio'],
                'has_avatar'=>!empty($pur['avatar_data']),'created_at'=>$pur['created_at'],'last_seen'=>$pur['last_seen'],
                'admin_of'=>$purForums],'posts'=>$purPosts]); break;
        case 'comments':
            $pid=(int)($_GET['post_id']??0); if (!$pid) { http_response_code(400); echo json_encode(['error'=>'post_id required']); break; }
            $top=get_comments($db,$pid); foreach ($top as &$c) $c['replies']=get_comments($db,$pid,(int)$c['id']);
            echo json_encode(['comments'=>$top]); break;
        case 'create_comment':
            $pid=(int)($input['post_id']??0); $ct=trim($input['content']??''); $par=isset($input['parent_id'])?(int)$input['parent_id']:null;
            if (!$pid||!$ct) { http_response_code(400); echo json_encode(['error'=>'post_id and content required']); break; }
            if (strlen($ct)>5000) { http_response_code(400); echo json_encode(['error'=>'Comment too long']); break; }
            $db->prepare("INSERT INTO comments (post_id,parent_id,author,user_id,content) VALUES (:pid,:par,:a,:uid,:c)")
               ->execute([':pid'=>$pid,':par'=>$par,':a'=>$currentUser['display_name'],':uid'=>(int)$currentUser['id'],':c'=>$ct]);
            echo json_encode(['ok'=>true,'comment_id'=>(int)$db->lastInsertId()]); break;
        case 'vote':
            $pid=(int)($input['post_id']??0); $val=(int)($input['value']??0);
            if (!$pid||!in_array($val,[1,-1],true)) { http_response_code(400); echo json_encode(['error'=>'post_id and value(1 or -1) required']); break; }
            $voter=$currentUser['display_name'];
            $chk=$db->prepare("SELECT id FROM votes WHERE post_id=:pid AND voter=:v"); $chk->execute([':pid'=>$pid,':v'=>$voter]);
            if ($chk->fetch()) $db->prepare("UPDATE votes SET value=:val WHERE post_id=:pid AND voter=:v")->execute([':val'=>$val,':pid'=>$pid,':v'=>$voter]);
            else $db->prepare("INSERT INTO votes (post_id,voter,value) VALUES (:pid,:v,:val)")->execute([':pid'=>$pid,':v'=>$voter,':val'=>$val]);
            $db->prepare("UPDATE posts SET votes=(SELECT COALESCE(SUM(value),0) FROM votes WHERE post_id=:pid) WHERE id=:pid")->execute([':pid'=>$pid]);
            $r=$db->prepare("SELECT votes FROM posts WHERE id=:pid"); $r->execute([':pid'=>$pid]);
            echo json_encode(['ok'=>true,'votes'=>(int)$r->fetchColumn()]); break;
        default:
            http_response_code(404);
            echo json_encode(['error'=>'Unknown action','public'=>['generate_token','login','forums','posts','comments','user_profile'],
                              'authed'=>['me','rename','csrf','create_forum','create_post','delete_post','create_comment','vote']]);
    }
    exit;
}


//  Form Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Token gate
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'generate_token') {
            $user = create_user($db);
            $_SESSION['user_token'] = $user['token']; $_SESSION['new_token_show'] = $user['token'];
            setcookie('user_token', $user['token'], time()+60*60*24*365, '/', '', false, true);
            header("Location: ".$_SERVER['PHP_SELF']."?forum=".urlencode($_GET['forum']??'general')); exit;
        }
        if ($_POST['action'] === 'login_token') {
            $t = strtoupper(preg_replace('/[^A-F0-9\-]/','',trim($_POST['token']??'')));
            $user = user_by_token($db, $t);
            if ($user) {
                $_SESSION['user_token'] = $user['token'];
                setcookie('user_token', $user['token'], time()+60*60*24*365, '/', '', false, true);
                touch_user($db, $user['token']);
                header("Location: ".$_SERVER['PHP_SELF']."?forum=".urlencode($_GET['forum']??'general')); exit;
            } else { $authError = "Token not found. Please check and try again."; }
        }
        if ($_POST['action'] === 'logout') {
            unset($_SESSION['user_token']);
            setcookie('user_token', '', time()-1, '/', '', false, true);
            header("Location: ".$_SERVER['PHP_SELF']."?forum=".urlencode($_GET['forum']??'general')); exit;
        }
    }

    if ($currentUser) {
        csrf_verify();
        $forum = preg_replace('/[^\w\-]/','', $_GET['forum']??'general') ?: 'general';

        // Rename self
        if (isset($_POST['set_name'])) {
            $n = preg_replace('/[^a-zA-Z0-9_\-]/','',trim($_POST['set_name']));
            if ($n!==''&&strlen($n)<=30) $db->prepare("UPDATE users SET display_name=:n WHERE token=:t")->execute([':n'=>$n,':t'=>$currentUser['token']]);
            $r = (isset($_GET['page'])&&$_GET['page']==='profile') ? $_SERVER['PHP_SELF'].'?page=profile' : $_SERVER['PHP_SELF'].'?forum='.urlencode($forum);
            header("Location: $r"); exit;
        }

        // Update bio
        if (isset($_POST['set_bio'])) {
            $bio = substr(trim($_POST['set_bio']??''),0,300);
            $db->prepare("UPDATE users SET bio=:b WHERE token=:t")->execute([':b'=>$bio,':t'=>$currentUser['token']]);
            header("Location: ".$_SERVER['PHP_SELF']."?page=profile"); exit;
        }

        // Upload avatar
        if (isset($_POST['upload_avatar'], $_FILES['avatar'])) {
            try {
                $img = validate_image($_FILES['avatar']);
                $db->prepare("UPDATE users SET avatar_data=:d,avatar_mime=:m WHERE token=:t")
                   ->execute([':d'=>$img['data'],':m'=>$img['mime'],':t'=>$currentUser['token']]);
            } catch (RuntimeException $e) { $_SESSION['profile_error'] = $e->getMessage(); }
            header("Location: ".$_SERVER['PHP_SELF']."?page=profile"); exit;
        }

        // Delete account
        if (isset($_POST['delete_account'])) {
            $uid = (int)$currentUser['id'];
            $db->prepare("UPDATE forums SET admin_id=NULL WHERE admin_id=:uid")->execute([':uid'=>$uid]);
            $pids = $db->prepare("SELECT id FROM posts WHERE user_id=:uid"); $pids->execute([':uid'=>$uid]);
            foreach ($pids->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $db->prepare("DELETE FROM comments WHERE post_id=:pid")->execute([':pid'=>$pid]);
                $db->prepare("DELETE FROM votes WHERE post_id=:pid")->execute([':pid'=>$pid]);
            }
            $db->prepare("DELETE FROM posts WHERE user_id=:uid")->execute([':uid'=>$uid]);
            $db->prepare("DELETE FROM comments WHERE user_id=:uid")->execute([':uid'=>$uid]);
            $db->prepare("DELETE FROM users WHERE id=:uid")->execute([':uid'=>$uid]);
            unset($_SESSION['user_token']); setcookie('user_token','',time()-1,'/',  '','', false,true);
            header("Location: ".$_SERVER['PHP_SELF']); exit;
        }

        // Create forum
        if (isset($_POST['new_forum'])) {
            $nf = trim($_POST['new_forum']??'');
            if (forum_name_valid($nf)) {
                $db->prepare("INSERT OR IGNORE INTO forums (name,admin_id) VALUES (:n,:a)")->execute([':n'=>$nf,':a'=>(int)$currentUser['id']]);
                header("Location: index.php?forum=".urlencode($nf)); exit;
            }
        }

        // Rename forum
        if (isset($_POST['rename_forum'])) {
            $nfn = trim($_POST['rename_forum']??'');
            if (forum_name_valid($nfn) && is_forum_admin($db, $forum, (int)$currentUser['id'])) {
                try { $db->prepare("UPDATE forums SET name=:n WHERE name=:o")->execute([':n'=>$nfn,':o'=>$forum]);
                      header("Location: index.php?forum=".urlencode($nfn)); exit;
                } catch (Exception $e) {}
            }
            header("Location: index.php?forum=".urlencode($forum)); exit;
        }

        // Delete forum 
        if (isset($_POST['delete_forum'])) {
            if (is_forum_admin($db, $forum, (int)$currentUser['id'])) {
                $fr = $db->prepare("SELECT id FROM forums WHERE name=:n"); $fr->execute([':n'=>$forum]); $fid=(int)$fr->fetchColumn();
                if ($fid) {
                    $ps = $db->prepare("SELECT id FROM posts WHERE forum_id=:fid"); $ps->execute([':fid'=>$fid]);
                    foreach ($ps->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                        $db->prepare("DELETE FROM comments WHERE post_id=:pid")->execute([':pid'=>$pid]);
                        $db->prepare("DELETE FROM votes WHERE post_id=:pid")->execute([':pid'=>$pid]);
                    }
                    $db->prepare("DELETE FROM posts WHERE forum_id=:fid")->execute([':fid'=>$fid]);
                    $db->prepare("DELETE FROM forums WHERE id=:fid")->execute([':fid'=>$fid]);
                }
            }
            header("Location: index.php?forum=general"); exit;
        }

        // Create post
        if (isset($_POST['content']) && !isset($_POST['comment_post_id'])) {
            $ct = trim($_POST['content']??''); $imgData=null; $imgMime=null;
            if (!empty($_FILES['post_image']['name'])) {
                try { $img=validate_image($_FILES['post_image']); $imgData=$img['data']; $imgMime=$img['mime']; }
                catch (RuntimeException $e) { $_SESSION['post_error']=$e->getMessage(); header("Location: index.php?forum=".urlencode($forum)); exit; }
            }
            if ($ct!==''&&strlen($ct)<=10000) {
                $db->prepare("INSERT OR IGNORE INTO forums (name,admin_id) VALUES (:n,:a)")->execute([':n'=>$forum,':a'=>(int)$currentUser['id']]);
                $db->prepare("INSERT INTO posts (forum_id,author,user_id,content,image_data,image_mime) VALUES ((SELECT id FROM forums WHERE name=:f),:a,:uid,:c,:id,:im)")
                   ->execute([':f'=>$forum,':a'=>$displayName,':uid'=>(int)$currentUser['id'],':c'=>$ct,':id'=>$imgData,':im'=>$imgMime]);
            }
            header("Location: index.php?forum=".urlencode($forum)); exit;
        }

        // Delete post
        if (isset($_POST['delete_post_id'])) {
            $pid=(int)$_POST['delete_post_id'];
            $r=$db->prepare("SELECT p.user_id,f.name AS forum_name FROM posts p JOIN forums f ON p.forum_id=f.id WHERE p.id=:id"); $r->execute([':id'=>$pid]); $p=$r->fetch(PDO::FETCH_ASSOC);
            if ($p && ((int)$p['user_id']===(int)$currentUser['id'] || is_forum_admin($db,$p['forum_name'],(int)$currentUser['id']))) {
                $db->prepare("DELETE FROM comments WHERE post_id=:id")->execute([':id'=>$pid]);
                $db->prepare("DELETE FROM votes WHERE post_id=:id")->execute([':id'=>$pid]);
                $db->prepare("DELETE FROM posts WHERE id=:id")->execute([':id'=>$pid]);
            }
            header("Location: index.php?forum=".urlencode($forum)); exit;
        }

        // Comments / replies
        if (isset($_POST['comment_post_id'], $_POST['comment_content'])) {
            $ct=trim($_POST['comment_content']??''); $pid=(int)$_POST['comment_post_id'];
            $par=isset($_POST['parent_id'])&&$_POST['parent_id']!==''?(int)$_POST['parent_id']:null;
            if ($ct!==''&&strlen($ct)<=5000)
                $db->prepare("INSERT INTO comments (post_id,parent_id,author,user_id,content) VALUES (:pid,:par,:a,:uid,:c)")
                   ->execute([':pid'=>$pid,':par'=>$par,':a'=>$displayName,':uid'=>(int)$currentUser['id'],':c'=>$ct]);
            header("Location: index.php?forum=".urlencode($forum)."#post".$pid); exit;
        }
    }
}


// Vote
$forum = preg_replace('/[^\w\-]/','', $_GET['forum']??'general') ?: 'general';
if ($currentUser && isset($_GET['vote'], $_GET['post_id'], $_GET['vtok'])) {
    if (hash_equals(csrf_token(), $_GET['vtok'])) {
        $pid=(int)$_GET['post_id']; $val=($_GET['vote']==='up')?1:-1; $voter=$displayName;
        $chk=$db->prepare("SELECT id FROM votes WHERE post_id=:pid AND voter=:v"); $chk->execute([':pid'=>$pid,':v'=>$voter]);
        if ($chk->fetch()) $db->prepare("UPDATE votes SET value=:val WHERE post_id=:pid AND voter=:v")->execute([':val'=>$val,':pid'=>$pid,':v'=>$voter]);
        else $db->prepare("INSERT INTO votes (post_id,voter,value) VALUES (:pid,:v,:val)")->execute([':pid'=>$pid,':v'=>$voter,':val'=>$val]);
        $db->prepare("UPDATE posts SET votes=(SELECT COALESCE(SUM(value),0) FROM votes WHERE post_id=:pid) WHERE id=:pid")->execute([':pid'=>$pid]);
    }
    header("Location: index.php?forum=".urlencode($forum)); exit;
}


// Current Page
$page = $_GET['page'] ?? 'forum';


// Profile page data
$profileUser = null; $profilePosts = []; $profileForums = [];
$isOwnProfile = false; $viewingOtherProfile = false;
if ($page === 'profile') {
    $viewUid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
    if ($viewUid && (!$currentUser || $viewUid !== (int)$currentUser['id'])) {
        $pu = $db->prepare("SELECT id,display_name,bio,avatar_data,created_at,last_seen FROM users WHERE id=:id");
        $pu->execute([':id' => $viewUid]); $profileUser = $pu->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($profileUser) {
            $viewingOtherProfile = true;
            $ps = $db->prepare("SELECT p.*,f.name AS forum_name FROM posts p JOIN forums f ON p.forum_id=f.id WHERE p.user_id=:uid ORDER BY p.created_at DESC LIMIT 20");
            $ps->execute([':uid'=>$viewUid]); $profilePosts = $ps->fetchAll(PDO::FETCH_ASSOC);
            $fs = $db->prepare("SELECT name FROM forums WHERE admin_id=:uid ORDER BY name");
            $fs->execute([':uid'=>$viewUid]); $profileForums = $fs->fetchAll(PDO::FETCH_COLUMN);
        }
    } elseif ($currentUser) {
        $isOwnProfile = true;
        $profileUser = user_by_token($db, $currentUser['token']);
        $ps = $db->prepare("SELECT p.*,f.name AS forum_name FROM posts p JOIN forums f ON p.forum_id=f.id WHERE p.user_id=:uid ORDER BY p.created_at DESC LIMIT 20");
        $ps->execute([':uid'=>(int)$currentUser['id']]); $profilePosts = $ps->fetchAll(PDO::FETCH_ASSOC);
        $fs = $db->prepare("SELECT name FROM forums WHERE admin_id=:uid ORDER BY name");
        $fs->execute([':uid'=>(int)$currentUser['id']]); $profileForums = $fs->fetchAll(PDO::FETCH_COLUMN);
    }
}


// Forum page data
$forums=[]; $posts=[]; $isCurForumAdmin=false; $forumAdminName=null;
if ($page === 'forum') {
    $db->prepare("INSERT OR IGNORE INTO forums (name,admin_id) VALUES (:n,NULL)")->execute([':n'=>$forum]);
    $forums = $db->query("SELECT name FROM forums ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $stmt = $db->prepare("SELECT p.*,CASE WHEN p.image_data IS NOT NULL THEN 1 ELSE 0 END AS has_image FROM posts p JOIN forums f ON p.forum_id=f.id WHERE f.name=:f ORDER BY p.created_at DESC");
    $stmt->execute([':f'=>$forum]); $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $isCurForumAdmin = $currentUser ? is_forum_admin($db, $forum, (int)$currentUser['id']) : false;
    $fa = $db->prepare("SELECT u.display_name FROM forums fo JOIN users u ON fo.admin_id=u.id WHERE fo.name=:n");
    $fa->execute([':n'=>$forum]); $forumAdminName = $fa->fetchColumn() ?: null;
}


$csrfToken    = csrf_token();
$showGate     = !$currentUser;
$newTokenShow = $_SESSION['new_token_show'] ?? null;
$postError    = $_SESSION['post_error']    ?? null;
$profileError = $_SESSION['profile_error'] ?? null;
unset($_SESSION['new_token_show'], $_SESSION['post_error'], $_SESSION['profile_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kommunities<?php
        if ($page==='profile' && $profileUser) {
            echo $viewingOtherProfile
                ? ' - ' . htmlspecialchars($profileUser['display_name']) . '\'s Profile'
                : ' - Profile';
        } elseif ($page==='forum') {
            echo ' - k/'.htmlspecialchars($forum);
        }
    ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --teal-lightest:#b2d8d8; --teal-light:#66b2b2;
            --teal-mid:#008080;      --teal-dark:#006666; --teal-darkest:#004c4c;
            --bg-page:#e8f4f4;       --bg-card:#ffffff;   --bg-subtle:#e8f4f4;
            --text-primary:#1a1a1a;  --text-secondary:#4b5563; --text-muted:#9ca3af;
            --text-author:#006666;   --border-color:#66b2b2;   --border-subtle:#b2d8d8;
            --input-bg:#ffffff;      --input-text:#1a1a1a;
            --modal-bg:#ffffff;
            --tab-inactive-bg:#ffffff; --tab-inactive-text:#555;
            --vote-strip-bg:#e8f4f4; --vote-btn-bg:#ffffff;
            --vote-btn-border:#ccc;  --vote-btn-text:#666;
            --reply-bg:#ffffff;      --reply-border:#b2d8d8;
            --forum-hover:#f0fafa;   --forum-active-bg:#d4ecec; --forum-active-text:#004c4c;
            --warn-bg:#fffbeb;       --warn-border:#fcd34d;     --warn-text:#92400e;
            --info-bg:#d4ecec;       --info-text:#004c4c;
            --danger-bg:#fee2e2;     --danger-text:#991b1b;     --danger-border:#fca5a5;
        }
        html.dark {
            --bg-page:#0d1f1f;       --bg-card:#132929;   --bg-subtle:#0a1a1a;
            --text-primary:#e8f4f4;  --text-secondary:#a8d0d0; --text-muted:#7ab8b8;
            --text-author:#66b2b2;   --border-color:#1e4a4a;   --border-subtle:#1a3a3a;
            --input-bg:#0d2a2a;      --input-text:#e8f4f4;
            --modal-bg:#132929;
            --tab-inactive-bg:#0d2a2a; --tab-inactive-text:#a8d0d0;
            --vote-strip-bg:#0a1a1a; --vote-btn-bg:#0d2a2a;
            --vote-btn-border:#1e4a4a; --vote-btn-text:#b2d8d8;
            --reply-bg:#0d2a2a;      --reply-border:#1a3a3a;
            --forum-hover:#0d2a2a;   --forum-active-bg:#1a3a3a; --forum-active-text:#b2d8d8;
            --warn-bg:#2a1f05;       --warn-border:#7a5a10;     --warn-text:#fcd34d;
            --info-bg:#1a3a3a;       --info-text:#b2d8d8;
            --danger-bg:#3a0f0f;     --danger-text:#fca5a5;     --danger-border:#7f1d1d;
        }
        body { background-color:var(--bg-page); color:var(--text-primary); }
        .card { background-color:var(--bg-card); }
        .btn-primary  { background-color:var(--teal-mid); color:#fff; transition:background-color .15s; }
        .btn-primary:hover   { background-color:var(--teal-dark); }
        .btn-primary:active  { background-color:var(--teal-darkest); }
        .btn-secondary { background-color:var(--teal-lightest); color:var(--teal-darkest); transition:background-color .15s,color .15s; }
        html.dark .btn-secondary { background-color:#1a3a3a; color:#b2d8d8; }
        .btn-secondary:hover { background-color:var(--teal-light); color:#fff; }
        .btn-danger { background-color:#dc2626; color:#fff; transition:background-color .15s; }
        .btn-danger:hover { background-color:#b91c1c; }
        .forum-active { background-color:var(--forum-active-bg)!important; color:var(--forum-active-text)!important; font-weight:600; }
        .forum-link   { color:var(--text-primary); }
        .forum-link:hover { background-color:var(--forum-hover); }
        input[type=text],input[type=file],textarea {
            background-color:var(--input-bg); color:var(--input-text); border-color:var(--border-color);
        }
        input::placeholder,textarea::placeholder { color:var(--text-muted); }
        input:focus,textarea:focus { outline:none; box-shadow:0 0 0 3px rgba(0,128,128,.25); border-color:var(--teal-mid)!important; }
        .token-blur { filter:blur(6px); transition:filter .2s; cursor:pointer; user-select:none; }
        .token-blur:hover,.token-blur.revealed { filter:none; }
        .modal-bg  { background:rgba(0,0,0,.65); backdrop-filter:blur(2px); }
        .modal-card { background-color:var(--modal-bg); color:var(--text-primary); }
        .tab-active   { background-color:var(--teal-mid)!important; color:#fff!important; }
        .tab-inactive { background-color:var(--tab-inactive-bg); color:var(--tab-inactive-text); }
        .tab-inactive:hover { background-color:var(--info-bg); color:var(--info-text); }
        .vote-btn { background:var(--vote-btn-bg); border:1px solid var(--vote-btn-border); color:var(--vote-btn-text); transition:background .15s,border-color .15s,color .15s; }
        .vote-btn-up:hover   { background-color:var(--teal-mid); border-color:var(--teal-mid); color:#fff; }
        .vote-btn-down:hover { background-color:#cc4444; border-color:#cc4444; color:#fff; }
        .site-header { background-color:var(--teal-darkest); color:#fff; }
        .warning-strip { background-color:var(--warn-bg); border-color:var(--warn-border); color:var(--warn-text); }
        .info-strip  { background-color:var(--info-bg); color:var(--info-text); }
        .danger-strip { background-color:var(--danger-bg); border:1px solid var(--danger-border); color:var(--danger-text); }
        .dm-toggle { display:inline-flex; align-items:center; gap:6px; padding:4px 10px 4px 6px; border-radius:20px; background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25); cursor:pointer; font-size:12px; color:#fff; transition:background .2s; user-select:none; }
        .dm-toggle:hover { background:rgba(255,255,255,.25); }
        .dm-track { width:28px; height:16px; border-radius:8px; background:rgba(255,255,255,.3); position:relative; flex-shrink:0; transition:background .2s; }
        .dm-thumb { position:absolute; top:2px; left:2px; width:12px; height:12px; border-radius:50%; background:#fff; transition:transform .2s; }
        html.dark .dm-track { background:var(--teal-mid); }
        html.dark .dm-thumb { transform:translateX(12px); }
        .admin-badge { display:inline-block; background:var(--teal-mid); color:#fff; font-size:10px; padding:1px 7px; border-radius:20px; vertical-align:middle; margin-left:5px; letter-spacing:.5px; }
        .post-image { max-width:100%; max-height:400px; border-radius:8px; margin-top:10px; object-fit:cover; cursor:zoom-in; }
        .avatar-img { width:72px; height:72px; border-radius:50%; object-fit:cover; border:2px solid var(--teal-mid); }
        .avatar-sm  { width:28px; height:28px; border-radius:50%; object-fit:cover; border:1px solid rgba(255,255,255,.4); }
        .avatar-placeholder { width:72px; height:72px; border-radius:50%; background:var(--teal-lightest); color:var(--teal-darkest); display:flex; align-items:center; justify-content:center; font-size:28px; font-weight:700; border:2px solid var(--teal-mid); }
        .avatar-sm-placeholder { width:28px; height:28px; border-radius:50%; background:rgba(255,255,255,.2); display:inline-flex; align-items:center; justify-content:center; font-size:13px; font-weight:700; }
    </style>
</head>
<body class="min-h-screen">

<?php if ($showGate): ?>
<!-- Login Gate -->
<div class="fixed inset-0 modal-bg z-50 flex items-center justify-center p-4">
    <div class="modal-card rounded-2xl shadow-2xl w-full max-w-md p-8 space-y-6">
        <div class="text-center space-y-1">
            <h1 class="text-3xl font-bold" style="color:var(--teal-mid)">Kommunities</h1>
            <p class="text-sm" style="color:var(--text-muted)">Anonymous community forums. No accounts, no email.</p>
        </div>
        <div class="flex rounded-xl overflow-hidden border text-sm font-medium" style="border-color:var(--border-color)">
            <button onclick="showTab('new')" id="tab-new" class="flex-1 py-2.5 transition-colors tab-active">New Identity</button>
            <button onclick="showTab('login')" id="tab-login" class="flex-1 py-2.5 transition-colors tab-inactive">I have a token</button>
        </div>
        <div id="panel-new" class="space-y-4">
            <div class="info-strip rounded-xl p-4 text-sm space-y-1">
                <p class="font-semibold">How it works:</p>
                <p>1. Click below to generate a random username + secret token.</p>
                <p>2. <strong>Save the token</strong> — it's the only way to log back in.</p>
                <p>3. Use it across any device or browser.</p>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="generate_token">
                <button type="submit" class="w-full btn-primary font-semibold py-3 rounded-xl text-base">Generate my identity →</button>
            </form>
        </div>
        <div id="panel-login" class="space-y-4 hidden">
            <?php if ($authError): ?><div class="bg-red-50 border border-red-200 text-red-600 text-sm rounded-lg px-3 py-2"><?= htmlspecialchars($authError) ?></div><?php endif; ?>
            <p class="text-sm" style="color:var(--text-secondary)">Paste your token to restore your identity on this device.</p>
            <form method="post" class="space-y-3">
                <input type="hidden" name="action" value="login_token">
                <input type="text" name="token" required autofocus placeholder="XXXX-XXXX-XXXX-XXXX" maxlength="19"
                    class="w-full border-2 rounded-xl p-3 font-mono text-center tracking-widest text-lg uppercase"
                    style="border-color:var(--border-color)"
                    oninput="this.value=this.value.toUpperCase().replace(/[^A-F0-9]/g,'').replace(/(.{4})(?=.)/g,'$1-').slice(0,19)">
                <button type="submit" class="w-full btn-primary font-semibold py-3 rounded-xl">Login with token →</button>
            </form>
        </div>
        <p class="text-center text-xs" style="color:var(--text-muted)">Completely anonymous · No tracking · Open source</p>
    </div>
</div>
<?php endif; ?>

<?php if ($newTokenShow): ?>
<!-- New Token Reveal -->
<div id="reveal-modal" class="fixed inset-0 modal-bg z-50 flex items-center justify-center p-4">
    <div class="modal-card rounded-2xl shadow-2xl w-full max-w-md p-8 space-y-5">
        <div class="text-center space-y-1">
            <h2 class="text-2xl font-bold" style="color:var(--text-primary)">Save your token!</h2>
            <p class="text-sm" style="color:var(--text-secondary)">This is shown <strong>only once</strong>. There is no recovery.</p>
        </div>
        <div class="rounded-2xl p-5 space-y-3 text-center" style="background:var(--info-bg);border:2px dashed var(--border-color)">
            <p class="text-xs uppercase tracking-widest font-semibold" style="color:var(--teal-dark)">Your Identity Token</p>
            <p id="token-val" class="font-mono text-2xl font-bold tracking-widest token-blur select-all"
               style="color:var(--teal-darkest)"><?= htmlspecialchars($newTokenShow) ?></p>
            <p class="text-xs" style="color:var(--text-muted)">↑ hover or click to reveal</p>
            <div class="flex gap-2 justify-center">
                <button onclick="revealToken()" class="btn-primary text-sm px-4 py-2 rounded-lg">Reveal</button>
                <button onclick="copyToken()" class="btn-secondary text-sm px-4 py-2 rounded-lg">Copy</button>
            </div>
            <p id="copy-ok" class="text-sm font-medium hidden" style="color:var(--teal-mid)">✓ Copied to clipboard!</p>
        </div>
        <div class="warning-strip border rounded-xl p-3 text-xs leading-relaxed">
            <strong>Warning:</strong> If you lose this token, your identity is <strong>permanently lost</strong>. Save it in a password manager.
        </div>
        <div class="text-sm text-center" style="color:var(--text-secondary)">
            Your username: <strong style="color:var(--text-primary)"><?= htmlspecialchars($displayName) ?></strong>
        </div>
        <button onclick="document.getElementById('reveal-modal').remove()" class="w-full btn-primary font-semibold py-3 rounded-xl">I've saved my token — enter →</button>
    </div>
</div>
<?php endif; ?>

<?php if ($currentUser || $profileUser): ?>
<!-- Header -->
<header class="site-header sticky top-0 z-40 shadow-md">
    <div class="max-w-6xl mx-auto flex flex-wrap justify-between items-center gap-3 p-4 md:p-5">
        <a href="index.php?forum=general" class="text-2xl md:text-3xl font-bold tracking-tight" style="color:var(--teal-lightest)">Kommunities</a>
        <div class="flex items-center gap-3 text-sm flex-wrap">
        <?php if ($currentUser): ?>
            <a href="index.php?page=profile" class="flex items-center gap-2" style="color:var(--teal-lightest)">
                <?php if (!empty($currentUser['avatar_data'])): ?>
                <img src="index.php?img=avatar&id=<?= (int)$currentUser['id'] ?>" alt="avatar" class="avatar-sm">
                <?php else: ?>
                <span class="avatar-sm-placeholder"><?= htmlspecialchars(mb_strtoupper(mb_substr($displayName,0,1))) ?></span>
                <?php endif; ?>
                <span>Signed in as <b class="text-white"><?= htmlspecialchars($displayName) ?></b></span>
            </a>
            <button onclick="document.getElementById('rename-panel').classList.toggle('hidden')"
                class="text-xs underline underline-offset-2" style="color:var(--teal-lightest)">rename</button>
            <span style="color:rgba(255,255,255,.2)">|</span>
            <form method="post" class="inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="text-xs underline underline-offset-2" style="color:#ffaaaa">switch identity</button>
            </form>
            <span style="color:rgba(255,255,255,.2)">|</span>
        <?php else: ?>
            <a href="index.php" class="text-xs underline underline-offset-2" style="color:var(--teal-lightest)">Sign in / Join</a>
            <span style="color:rgba(255,255,255,.2)">|</span>
        <?php endif; ?>
            <button onclick="toggleDark()" class="dm-toggle">
                <span id="dm-icon">☀</span>
                <span class="dm-track"><span class="dm-thumb"></span></span>
                <span id="dm-label">Light</span>
            </button>
        </div>
    </div>
    <?php if ($currentUser): ?>
    <div id="rename-panel" class="hidden border-t max-w-6xl mx-auto px-4 py-3" style="border-color:var(--teal-dark)">
        <form method="post" class="flex gap-2 items-center flex-wrap">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="text" name="set_name" maxlength="30" pattern="[\w\-]+" placeholder="New display name (letters, numbers, _ or -)"
                class="border rounded-lg p-2 text-sm w-72" style="border-color:var(--teal-light);background:var(--input-bg);color:var(--input-text)">
            <button type="submit" class="btn-primary px-4 py-2 rounded-lg text-sm font-medium">Save</button>
        </form>
    </div>
    <?php endif; ?>
</header>

<?php if ($page === 'profile' && $profileUser): ?>
<!-- PROFILE PAGE -->
<div class="max-w-3xl mx-auto p-4 md:p-6 space-y-6 mt-4">

    <?php if ($profileError): ?>
    <div class="danger-strip rounded-xl p-3 text-sm"><?= htmlspecialchars($profileError) ?></div>
    <?php endif; ?>

    <?php if ($viewingOtherProfile): ?>
    <a href="javascript:history.back()" class="inline-flex items-center gap-1 text-sm hover:underline" style="color:var(--teal-mid)">
        ← Back
    </a>
    <?php endif; ?>

    <!-- Profile card -->
    <div class="card shadow-sm rounded-xl p-6" style="border-left:4px solid var(--teal-mid)">
        <div class="flex items-start gap-5 flex-wrap">
            <div class="flex-shrink-0">
                <?php if (!empty($profileUser['avatar_data'])): ?>
                <img src="index.php?img=avatar&id=<?= (int)$profileUser['id'] ?>" alt="avatar" class="avatar-img">
                <?php else: ?>
                <div class="avatar-placeholder"><?= htmlspecialchars(mb_strtoupper(mb_substr($profileUser['display_name'],0,1))) ?></div>
                <?php endif; ?>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-2xl font-bold" style="color:var(--teal-mid)"><?= htmlspecialchars($profileUser['display_name']) ?></h2>
                <p class="text-xs mt-1" style="color:var(--text-muted)">
                    Member since <?= htmlspecialchars(substr($profileUser['created_at'],0,10)) ?>
                    · Last seen <?= htmlspecialchars(substr($profileUser['last_seen'],0,10)) ?>
                </p>
                <?php if ($profileForums): ?>
                <p class="text-xs mt-1" style="color:var(--text-muted)">
                    Admin of:
                    <?php foreach ($profileForums as $pf): ?>
                    <a href="index.php?forum=<?= urlencode($pf) ?>" class="underline mr-1" style="color:var(--teal-mid)">k/<?= htmlspecialchars($pf) ?></a>
                    <?php endforeach; ?>
                </p>
                <?php endif; ?>
                <p class="text-sm mt-3" style="color:var(--text-secondary)"><?= htmlspecialchars($profileUser['bio'] ?: 'No bio yet.') ?></p>
            </div>
        </div>
    </div>

<?php if ($isOwnProfile): ?>
    <!-- Edit bio -->
    <div class="card shadow-sm rounded-xl p-5">
        <h3 class="text-sm font-semibold mb-3" style="color:var(--text-secondary)">Edit Bio</h3>
        <form method="post" class="space-y-2">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <textarea name="set_bio" maxlength="300" rows="3"
                class="w-full border rounded-lg p-3 text-sm resize-none" style="border-color:var(--border-color)"
                placeholder="Tell the community about yourself (max 300 chars)…"><?= htmlspecialchars($profileUser['bio']??'') ?></textarea>
            <button type="submit" class="btn-primary px-4 py-2 rounded-lg text-sm font-medium">Update Bio</button>
        </form>
    </div>

    <!-- Upload avatar -->
    <div class="card shadow-sm rounded-xl p-5">
        <h3 class="text-sm font-semibold mb-3" style="color:var(--text-secondary)">Profile Picture</h3>
        <form method="post" enctype="multipart/form-data" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="upload_avatar" value="1">
            <div class="flex items-center gap-3">
                <label class="flex items-center gap-1.5 cursor-pointer btn-secondary px-3 py-1.5 rounded-lg text-xs font-medium select-none" title="JPEG, PNG, GIF or WebP · max 2 MB · EXIF stripped for privacy">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                    </svg>
                    <span id="avatar-label">Choose image</span>
                    <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" class="sr-only"
                        onchange="document.getElementById('avatar-label').textContent=this.files[0]?this.files[0].name:'Choose image'">
                </label>
                <button type="submit" class="btn-primary px-4 py-2 rounded-lg text-sm font-medium">Upload Picture</button>
            </div>
        </form>
    </div>

    <!-- Change display name -->
    <div class="card shadow-sm rounded-xl p-5">
        <h3 class="text-sm font-semibold mb-3" style="color:var(--text-secondary)">Change Display Name</h3>
        <form method="post" class="flex gap-2 items-center flex-wrap">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="text" name="set_name" maxlength="30" pattern="[\w\-]+"
                value="<?= htmlspecialchars($profileUser['display_name']) ?>"
                class="border rounded-lg p-2 text-sm flex-1 min-w-0" style="border-color:var(--border-color)">
            <button type="submit" class="btn-primary px-4 py-2 rounded-lg text-sm font-medium">Save</button>
        </form>
    </div>
<?php endif; ?>

    <!-- Recent posts -> Profiles -->
    <?php if ($profilePosts): ?>
    <div class="card shadow-sm rounded-xl p-5">
        <h3 class="text-sm font-semibold mb-4" style="color:var(--text-secondary)">Recent Posts</h3>
        <div class="space-y-3">
            <?php foreach ($profilePosts as $pp): ?>
            <div class="rounded-lg p-3" style="background:var(--bg-subtle)">
                <p class="text-sm whitespace-pre-wrap" style="color:var(--text-primary)"><?= htmlspecialchars(mb_substr($pp['content'],0,200)) ?><?= mb_strlen($pp['content'])>200?'…':'' ?></p>
                <p class="text-xs mt-1" style="color:var(--text-muted)">
                    in <a href="index.php?forum=<?= urlencode($pp['forum_name']) ?>" class="underline" style="color:var(--teal-mid)">k/<?= htmlspecialchars($pp['forum_name']) ?></a>
                    · <?= htmlspecialchars(substr($pp['created_at'],0,16)) ?> · <?= (int)$pp['votes'] ?> votes
                </p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isOwnProfile): ?>
    <!-- Danger zone -->
    <div class="card shadow-sm rounded-xl p-5 danger-strip">
        <h3 class="text-sm font-bold mb-2">Danger Zone</h3>
        <p class="text-xs mb-3">Deleting your account is <strong>permanent and irreversible</strong>. All your posts, comments, and admin roles will be removed.</p>
        <button onclick="document.getElementById('delete-account-modal').classList.remove('hidden')"
            class="btn-danger px-4 py-2 rounded-lg text-sm font-medium">Delete My Account</button>
    </div>
    <?php endif; ?>
</div>

<?php if ($isOwnProfile): ?>
<!-- Delete account modal -->
<div id="delete-account-modal" class="fixed inset-0 modal-bg z-50 items-center justify-center p-4 hidden flex">
    <div class="modal-card rounded-2xl shadow-2xl w-full max-w-sm p-7 space-y-5">
        <h2 class="text-xl font-bold" style="color:#dc2626">Delete Account?</h2>
        <p class="text-sm" style="color:var(--text-secondary)">This will permanently delete all your posts and comments and remove your admin rights. <strong>This cannot be undone.</strong></p>
        <div class="flex gap-3">
            <form method="post" class="flex-1">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="delete_account" value="1">
                <button type="submit" class="w-full btn-danger py-2 rounded-lg font-semibold text-sm">Yes, delete everything</button>
            </form>
            <button onclick="document.getElementById('delete-account-modal').classList.add('hidden')"
                class="flex-1 btn-secondary py-2 rounded-lg font-semibold text-sm">Cancel</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- Forum Page -->
<div class="max-w-6xl mx-auto p-4 md:p-6 grid grid-cols-1 md:grid-cols-12 gap-6 mt-4">

    <div class="col-span-1 md:col-span-9 space-y-5">

        <!-- Forum heading -->
        <div class="card shadow-sm rounded-xl p-5" style="border-left:4px solid var(--teal-mid)">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-2xl font-semibold" style="color:var(--teal-mid)">
                        k/<?= htmlspecialchars($forum) ?>
                        <?php if ($isCurForumAdmin): ?><span class="admin-badge">admin</span><?php endif; ?>
                    </h2>
                    <?php if ($forumAdminName): ?>
                    <p class="text-xs mt-1" style="color:var(--text-muted)">
                        Managed by <span style="color:var(--text-author)"><?= htmlspecialchars($forumAdminName) ?></span>
                    </p>
                    <?php endif; ?>
                </div>
                <?php if ($isCurForumAdmin): ?>
                <button onclick="document.getElementById('admin-panel').classList.toggle('hidden')"
                    class="btn-secondary text-xs px-3 py-1.5 rounded-lg font-medium">⚙ Manage Forum</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isCurForumAdmin): ?>
        <!-- Admin Panel -->
        <div id="admin-panel" class="hidden card shadow-sm rounded-xl p-5 space-y-4" style="border:2px solid var(--teal-light)">
            <h3 class="text-sm font-semibold" style="color:var(--teal-mid)">Forum Admin Controls</h3>
            <div class="flex flex-wrap gap-4 items-end">
                <form method="post" class="flex gap-2 items-center flex-1 min-w-0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="text" name="rename_forum" maxlength="60" pattern="[\w\-]+"
                        value="<?= htmlspecialchars($forum) ?>" placeholder="New forum name"
                        class="border rounded-lg p-2 text-sm flex-1 min-w-0" style="border-color:var(--border-color)">
                    <button type="submit" class="btn-primary px-3 py-2 rounded-lg text-sm whitespace-nowrap">Rename</button>
                </form>
                <button onclick="document.getElementById('delete-forum-modal').classList.remove('hidden')"
                    class="btn-danger text-sm px-4 py-2 rounded-lg font-medium whitespace-nowrap">Delete Forum</button>
            </div>
        </div>
        <!-- Delete forum modal -->
        <div id="delete-forum-modal" class="fixed inset-0 modal-bg z-50 items-center justify-center p-4 hidden flex">
            <div class="modal-card rounded-2xl shadow-2xl w-full max-w-sm p-7 space-y-5">
                <h2 class="text-xl font-bold" style="color:#dc2626">Delete k/<?= htmlspecialchars($forum) ?>?</h2>
                <p class="text-sm" style="color:var(--text-secondary)">This will permanently delete the forum and <strong>all posts and comments</strong> inside it. Cannot be undone.</p>
                <div class="flex gap-3">
                    <form method="post" class="flex-1">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="delete_forum" value="1">
                        <button type="submit" class="w-full btn-danger py-2 rounded-lg font-semibold text-sm">Yes, delete forum</button>
                    </form>
                    <button onclick="document.getElementById('delete-forum-modal').classList.add('hidden')"
                        class="flex-1 btn-secondary py-2 rounded-lg font-semibold text-sm">Cancel</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($postError): ?>
        <div class="danger-strip rounded-xl p-3 text-sm"><?= htmlspecialchars($postError) ?></div>
        <?php endif; ?>

        <!-- Create Post -->
        <div class="card shadow-sm rounded-xl p-5">
            <h2 class="text-lg font-semibold mb-3" style="color:var(--text-secondary)">Create Post</h2>
            <form method="post" enctype="multipart/form-data" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <textarea name="content" required maxlength="10000"
                    class="w-full border rounded-lg p-3 text-sm resize-none h-24"
                    style="border-color:var(--border-color)"
                    placeholder="What's on your mind?"></textarea>
                <div class="flex items-center gap-3">
                    <label class="flex items-center gap-1.5 cursor-pointer btn-secondary px-3 py-1.5 rounded-lg text-xs font-medium select-none" title="Attach image (JPEG/PNG/GIF/WebP · max 2 MB)">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                        </svg>
                        <span id="attach-label">Attach image</span>
                        <input type="file" name="post_image" accept="image/jpeg,image/png,image/gif,image/webp" class="sr-only"
                            onchange="document.getElementById('attach-label').textContent=this.files[0]?this.files[0].name:'Attach image'">
                    </label>
                    <button type="submit" class="btn-primary px-5 py-2 rounded-lg text-sm font-medium">Post</button>
                </div>
            </form>
        </div>

        <!-- Posts -->
        <?php if ($posts): ?>
        <?php foreach ($posts as $post):
            $canDelete = (int)$post['user_id'] === (int)$currentUser['id'] || $isCurForumAdmin;
        ?>
        <div id="post<?= (int)$post['id'] ?>" class="card shadow-sm rounded-xl flex flex-col md:flex-row overflow-hidden">
            <!-- Vote strip -->
            <div class="flex md:flex-col flex-row items-center justify-center p-3 gap-2 md:gap-1 min-w-[3.5rem]"
                 style="background-color:var(--vote-strip-bg)">
                <a href="?forum=<?= urlencode($forum) ?>&vote=up&post_id=<?= (int)$post['id'] ?>&vtok=<?= urlencode($csrfToken) ?>"
                   class="flex items-center justify-center w-9 h-9 rounded-lg vote-btn vote-btn-up">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7"/>
                    </svg>
                </a>
                <span class="font-bold text-sm text-center min-w-[1.5rem]" style="color:var(--teal-mid)"><?= (int)$post['votes'] ?></span>
                <a href="?forum=<?= urlencode($forum) ?>&vote=down&post_id=<?= (int)$post['id'] ?>&vtok=<?= urlencode($csrfToken) ?>"
                   class="flex items-center justify-center w-9 h-9 rounded-lg vote-btn vote-btn-down">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                    </svg>
                </a>
            </div>
            <!-- Post content -->
            <div class="flex-1 p-5">
                <div class="flex items-start justify-between gap-2">
                    <p class="flex-1 whitespace-pre-wrap" style="color:var(--text-primary)"><?= htmlspecialchars($post['content']) ?></p>
                    <?php if ($canDelete): ?>
                    <button onclick="document.getElementById('dpm<?= (int)$post['id'] ?>').classList.remove('hidden')"
                        class="flex-shrink-0 btn-danger text-xs px-2 py-1 rounded opacity-70 hover:opacity-100 ml-2">Delete</button>
                    <?php endif; ?>
                </div>
                <?php if ($post['has_image']): ?>
                <img src="index.php?img=post&id=<?= (int)$post['id'] ?>" alt="<?= htmlspecialchars($post['content']) ?>" class="post-image">
                <?php endif; ?>
                <p class="text-xs mt-2 mb-4" style="color:var(--text-muted)">
                    by <?php if ($post['user_id']): ?><a href="index.php?page=profile&uid=<?= (int)$post['user_id'] ?>" class="font-medium hover:underline" style="color:var(--text-author)"><?= htmlspecialchars($post['author']) ?></a><?php else: ?><span class="font-medium" style="color:var(--text-author)"><?= htmlspecialchars($post['author']) ?></span><?php endif; ?>
                    · <?= htmlspecialchars($post['created_at']) ?>
                </p>
                <!-- Comments -->
                <div class="space-y-3 border-t pt-4" style="border-color:var(--border-subtle)">
                    <?php foreach (get_comments($db, (int)$post['id']) as $comment): ?>
                    <div class="rounded-lg p-3 space-y-2" style="background:var(--bg-subtle)">
                        <p class="text-sm whitespace-pre-wrap" style="color:var(--text-primary)"><?= htmlspecialchars($comment['content']) ?></p>
                        <p class="text-xs" style="color:var(--text-muted)">
                            <?php if ($comment['user_id']): ?><a href="index.php?page=profile&uid=<?= (int)$comment['user_id'] ?>" class="font-medium hover:underline" style="color:var(--text-author)"><?= htmlspecialchars($comment['author']) ?></a><?php else: ?><span class="font-medium" style="color:var(--text-author)"><?= htmlspecialchars($comment['author']) ?></span><?php endif; ?>
                            · <?= htmlspecialchars($comment['created_at']) ?>
                        </p>
                        <?php foreach (get_comments($db, (int)$post['id'], (int)$comment['id']) as $reply): ?>
                        <div class="rounded-lg p-2.5 ml-4" style="background:var(--reply-bg);border:1px solid var(--reply-border)">
                            <p class="text-sm whitespace-pre-wrap" style="color:var(--text-primary)"><?= htmlspecialchars($reply['content']) ?></p>
                            <p class="text-xs mt-1" style="color:var(--text-muted)">
                                <?php if ($reply['user_id']): ?><a href="index.php?page=profile&uid=<?= (int)$reply['user_id'] ?>" class="font-medium hover:underline" style="color:var(--text-author)"><?= htmlspecialchars($reply['author']) ?></a><?php else: ?><span class="font-medium" style="color:var(--text-author)"><?= htmlspecialchars($reply['author']) ?></span><?php endif; ?>
                                · <?= htmlspecialchars($reply['created_at']) ?>
                            </p>
                        </div>
                        <?php endforeach; ?>
                        <form method="post" class="flex gap-2 pt-1">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="comment_post_id" value="<?= (int)$post['id'] ?>">
                            <input type="hidden" name="parent_id" value="<?= (int)$comment['id'] ?>">
                            <input type="text" name="comment_content" maxlength="5000" required placeholder="Reply…"
                                class="flex-1 border rounded-lg p-2 text-sm" style="border-color:var(--border-color)">
                            <button type="submit" class="btn-secondary px-3 py-1 rounded-lg text-sm font-medium">Reply</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                    <form method="post" class="flex gap-2">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="comment_post_id" value="<?= (int)$post['id'] ?>">
                        <input type="text" name="comment_content" maxlength="5000" required placeholder="Add a comment…"
                            class="flex-1 border rounded-lg p-2 text-sm" style="border-color:var(--border-color)">
                        <button type="submit" class="btn-primary px-3 py-1 rounded-lg text-sm font-medium">Comment</button>
                    </form>
                </div>
            </div>
        </div>
        <?php if ($canDelete): ?>
        <!-- Delete post modal -->
        <div id="dpm<?= (int)$post['id'] ?>" class="fixed inset-0 modal-bg z-50 items-center justify-center p-4 hidden flex">
            <div class="modal-card rounded-2xl shadow-2xl w-full max-w-sm p-7 space-y-5">
                <h2 class="text-xl font-bold" style="color:#dc2626">Delete this post?</h2>
                <p class="text-sm" style="color:var(--text-secondary)">All comments will also be deleted. This cannot be undone.</p>
                <div class="flex gap-3">
                    <form method="post" class="flex-1">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="delete_post_id" value="<?= (int)$post['id'] ?>">
                        <button type="submit" class="w-full btn-danger py-2 rounded-lg font-semibold text-sm">Yes, delete</button>
                    </form>
                    <button onclick="document.getElementById('dpm<?= (int)$post['id'] ?>').classList.add('hidden')"
                        class="flex-1 btn-secondary py-2 rounded-lg font-semibold text-sm">Cancel</button>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="card shadow-sm rounded-xl p-8 text-center italic" style="color:var(--text-muted)">No posts yet. Be the first!</div>
        <?php endif; ?>
    </div>

    <!-- Sidebar -->
    <div class="col-span-1 md:col-span-3 space-y-4">
        <div class="card shadow-sm rounded-xl p-4 sticky top-20">
            <h2 class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:var(--teal-mid)">Forums</h2>
            <div class="flex flex-col gap-0.5">
                <?php foreach ($forums as $f): ?>
                <a href="index.php?forum=<?= urlencode($f) ?>"
                   class="px-3 py-2 rounded-lg text-sm transition-colors <?= $f===$forum?'forum-active':'forum-link' ?>">
                    k/<?= htmlspecialchars($f) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card shadow-sm rounded-xl p-4">
            <h2 class="text-xs font-semibold uppercase tracking-widest mb-3" style="color:var(--teal-mid)">New Forum</h2>
            <form method="post" class="space-y-2">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="text" name="new_forum" required maxlength="60" pattern="[\w\-]+"
                    title="Letters, numbers, hyphens and underscores only" placeholder="my-forum"
                    class="w-full border rounded-lg p-2 text-sm" style="border-color:var(--border-color)">
                <button type="submit" class="w-full btn-primary py-2 rounded-lg text-sm font-medium">Create (you'll be admin)</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php else: ?>
<div class="flex items-center justify-center min-h-screen">
    <p class="text-sm" style="color:var(--text-muted)">Loading…</p>
</div>
<?php endif; ?>

<script>
const html = document.documentElement;
function applyDark(on) {
    html.classList.toggle('dark', on);
    const icon = document.getElementById('dm-icon'), lbl = document.getElementById('dm-label');
    if (icon) icon.textContent = on ? '☾' : '☀';
    if (lbl)  lbl.textContent  = on ? 'Dark' : 'Light';
}
(function() {
    const s = localStorage.getItem('dm'), sys = window.matchMedia('(prefers-color-scheme: dark)').matches;
    applyDark(s !== null ? s === '1' : sys);
})();
function toggleDark() { const on = !html.classList.contains('dark'); applyDark(on); localStorage.setItem('dm', on?'1':'0'); }
function showTab(tab) {
    ['new','login'].forEach(function(t) {
        document.getElementById('panel-' + t).classList.toggle('hidden', t !== tab);
        document.getElementById('tab-' + t).className = 'flex-1 py-2.5 transition-colors ' + (t===tab?'tab-active':'tab-inactive');
    });
}
<?php if ($authError): ?>showTab('login');<?php endif; ?>
const tv = document.getElementById('token-val');
function revealToken() { if (tv) tv.classList.add('revealed'); }
function copyToken() {
    if (!tv) return;
    navigator.clipboard.writeText(tv.textContent.trim()).then(function() {
        tv.classList.add('revealed');
        const msg = document.getElementById('copy-ok');
        if (msg) msg.classList.remove('hidden');
    });
}
if (tv) tv.addEventListener('click', revealToken);
document.querySelectorAll('[id$="-modal"],[id^="dpm"]').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === m) m.classList.add('hidden'); });
});
</script>
</body>
</html>