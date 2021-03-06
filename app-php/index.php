<?php

ini_set( 'session.save_handler', 'files' );
ini_set( 'session.save_path', '/tmp/' );

require_once 'limonade/lib/limonade.php';

function configure()
{
    option('base_uri', '');
    option('session', 'isucon_session');

    $config = [
        'database' => [
            'dbname' => 'isucon',
            'host' => 'localhost',
            'port' => 3306,
            'username' => 'isucon',
            'password' => ''
        ]
    ];

    $db = null;
    try {
        $db = new PDO(
            'mysql:host=' . $config['database']['host'] . ';dbname=' . $config['database']['dbname']
,
            $config['database']['username'],
            $config['database']['password'],
            array(
                PDO::ATTR_PERSISTENT => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET CHARACTER SET `utf8`',
            )
        );
    } catch (PDOException $e) {
        halt("Connection faild: $e");
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    option('db_conn', $db);
}

function uri_for($path) {
    $scheme = isset($_SERVER['HTTPS']) ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_X_FORWARDED_HOST']) ?
        $_SERVER['HTTP_X_FORWARDED_HOST'] : $_SERVER['HTTP_HOST'];
    $base = $scheme . '://' . $host;
    return $base . $path;
}

function get($key) {
    // set returns already set value when value exists
    return set($key);
}

function before($route) {
    layout('layout.html.php');
    set('greeting', 'Hello');
    set('site_name', 'Isucon');

    $path = $_SERVER['QUERY_STRING'];
    $method = $route['method'];

    filter_session($route);

    $user = $_SESSION['user'] ?? null;
    set('user', $user);
    if ($user) {
        header('Cache-Control: private');
    }

    if ($path == '/signout' || $path == '/mypage' || $path == '/memo') {
        filter_require_user($route);
    }

    if ($path == '/signout' || $path == '/memo') {
        filter_anti_csrf($route);
    }
}

function filter_session($route) {
    set('session_id', session_id());
    set('session', $_SESSION);
}

function filter_require_user($route) {
    if (!get('user')) {
        return redirect('/');
    }
}

function filter_anti_csrf($route) {
    $sid = $_POST["sid"] ?? null;
    $token = $_SESSION["token"] ?? null;

    if ($sid != $token) {
        return halt(400);
    }
}

function markdown($content) {
    $fh = tmpfile();
    $metadata = stream_get_meta_data($fh);
    $filename = $metadata['uri'];
    fwrite($fh, $content);
    $html = shell_exec("/home/isucon/webapp/bin/markdown " . $filename);
    fclose($fh);
    return $html;
}

dispatch_get('/', function() {
    $db = option('db_conn');

    $stmt = $db->prepare('SELECT count(*) AS total FROM memos WHERE is_private=0');
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total = $result["total"];

    $stmt = $db->prepare('SELECT id, username, content, created_at FROM memos WHERE is_private=0 ORDER BY created_at DESC, id DESC LIMIT 100');
    $stmt->execute();
    $memos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    set('memos', $memos);
    set('page', 0);
    set('total', $total);

    return html('index.html.php');
});

dispatch_get('/recent/:page', function(){
    $db = option('db_conn');

    $stmt = $db->prepare('SELECT count(*) AS total FROM memos WHERE is_private=0');
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total = $result["total"];

    $page = params('page');
    $stmt = $db->prepare("SELECT id, username, content, created_at FROM memos WHERE is_private=0 ORDER BY created_at DESC, id DESC LIMIT 100 OFFSET " . $page * 100);
    $stmt->execute();
    $memos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    set('memos', $memos);
    set('page', $page);
    set('total', $total);

    return html('index.html.php');

});

dispatch_get('/signin', function() {
    return html('signin.html.php');
});

dispatch_post('/signout', function() {
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
    session_destroy();
    session_start();

    return redirect('/');
});

dispatch_post('/signin', function() {
    $db = option('db_conn');

    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $db->prepare('SELECT id, username, password, salt FROM users WHERE username = :username');
    $stmt->bindValue(':username', $username);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return render('signin.html.php');
    }

    if ($user['password'] == hash('sha256', $user['salt'] . $password, FALSE)) {
        #session_regenerate_id(true);
        $_SESSION['user'] = $user;
        $_SESSION['token'] = hash('sha256', rand(), FALSE);
        return redirect('/mypage');
    } else {
        return render('signin.html.php');
    }
});

dispatch_get('/mypage', function() {
    $db = option('db_conn');

    $user = get('user');

    $stmt = $db->prepare('SELECT id, content, is_private, created_at, updated_at FROM memos WHERE user = :user ORDER BY created_at DESC');
    $stmt->bindValue(':user', $user['id']);
    $stmt->execute();
    $memos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    set('memos', $memos);
    return html('mypage.html.php');
});

dispatch_post('/memo', function() {
    $db = option('db_conn');

    $user = get('user');
    $content = $_POST["content"];
    $is_private = isset($_POST["is_private"]) && $_POST["is_private"] != 0;

    $stmt = $db->prepare('INSERT INTO memos (user, content, is_private, created_at) VALUES (:user, :content, :is_private, now())');
    $stmt->bindValue(':user', $user['id']);
    $stmt->bindValue(':content', $content);
    $stmt->bindValue(':is_private', $is_private);
    $stmt->execute();

    $memo_id = $db->lastInsertId();
    return redirect('/memo/' . $memo_id);
});

dispatch_get('/memo/:id', function() {
    $db = option('db_conn');

    $user = get('user');
    $stmt = $db->prepare('SELECT id, user, username, content, is_private, created_at, updated_at FROM memos WHERE id = :id');
    $stmt->bindValue(':id', params('id'));
    $stmt->execute();
    $memo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$memo) {
        return halt(404);
    }
    if ($memo['is_private'] != 0) {
        if (!$user) {
            return halt(404);
        }
        if ($user['id'] != $memo['user']) {
            return halt(404);
        }
    }

    $memo['content_html'] = markdown($memo['content']);

    if ($user && $user['id'] == $memo['user']) {
        $cond = "";
    } else {
        $cond = "AND is_private=0";
    }

    $stmt = $db->prepare("SELECT id FROM memos WHERE user = :user " . $cond . " ORDER BY created_at");
    $stmt->bindValue(':user', $memo['user']);
    $stmt->execute();
    $memos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $older = null;
    $newer = null;
    for ($i = 0; $i < count($memos); $i++) {
        if ($memos[$i]['id'] == $memo['id']) {
            if ($i > 0) {
                $older = $memos[$i - 1];
            }
            if ($i < count($memos) - 1) {
                $newer = $memos[$i + 1];
            }
        }
    }   

    set('memo', $memo);
    set('older', $older);
    set('newer', $newer);

    return html('memo.html.php');
});

run();
