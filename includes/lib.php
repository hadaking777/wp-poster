<?php
/**
 * WordPress投稿ツール ── 共通処理
 *
 * 生成した文章を、WordPressのREST APIへ下書きとして投稿する。
 * 教材用サンプル：中身が読めるように、処理は素直に書いてある。
 */

define('WPP_DATA', __DIR__ . '/../data');
define('WPP_CONF', WPP_DATA . '/config.json');
define('WPP_DONE', WPP_DATA . '/posted.json');   // 二重投稿を防ぐ記録

function wpp_boot() {
    if (!is_dir(WPP_DATA)) { @mkdir(WPP_DATA, 0700, true); }
    $ht = WPP_DATA . '/.htaccess';
    if (!file_exists($ht)) { @file_put_contents($ht, wpp_deny_rule()); }
}

function wpp_config() {
    wpp_boot();
    if (!file_exists(WPP_CONF)) {
        $c = array(
            'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
            'wp_url'        => '',
            'wp_user'       => '',
            'wp_app_pass'   => '',
            'default_status'=> 'draft',
            'wait_seconds'  => 2,
        );
        wpp_save_config($c);
        return $c;
    }
    $d = json_decode(file_get_contents(WPP_CONF), true);
    return is_array($d) ? $d : array();
}

function wpp_save_config($c) {
    wpp_boot();
    file_put_contents(WPP_CONF, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @chmod(WPP_CONF, 0600);
}

/** 投稿済みの記録（キー => 投稿ID・URL・日時） */
function wpp_done() {
    wpp_boot();
    if (!file_exists(WPP_DONE)) { return array(); }
    $d = json_decode(file_get_contents(WPP_DONE), true);
    return is_array($d) ? $d : array();
}

function wpp_mark_done($key, $info) {
    $all = wpp_done();
    $all[$key] = $info;
    file_put_contents(WPP_DONE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @chmod(WPP_DONE, 0600);
}

function wpp_is_done($key) {
    $all = wpp_done();
    return $key !== '' && isset($all[$key]);
}

/** WordPressのREST APIを叩く共通処理 */
function wpp_api($conf, $path, $method = 'GET', $body = null, $extra_headers = array()) {
    $url = rtrim($conf['wp_url'], '/') . '/wp-json/wp/v2/' . ltrim($path, '/');
    $auth = base64_encode($conf['wp_user'] . ':' . $conf['wp_app_pass']);

    $headers = array_merge(array(
        'Authorization: Basic ' . $auth,
        'Accept: application/json',
        // WAFに素通りしやすいよう、普通のブラウザに近い名乗りにする
        'User-Agent: Mozilla/5.0 (compatible; WPPoster/1.0)',
    ), $extra_headers);

    $ch = curl_init($url);
    $opt = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_FOLLOWLOCATION => false,
    );
    if ($body !== null) { $opt[CURLOPT_POSTFIELDS] = $body; }
    curl_setopt_array($ch, $opt);

    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($res === false) {
        return array('ok' => false, 'code' => 0, 'data' => null, 'error' => '接続失敗: ' . $err, 'raw' => '');
    }
    $data = json_decode($res, true);

    if ($code < 200 || $code >= 300) {
        $msg = $data['message'] ?? mb_substr(strip_tags($res), 0, 300);
        return array('ok' => false, 'code' => $code, 'data' => $data, 'error' => $msg, 'raw' => $res);
    }
    return array('ok' => true, 'code' => $code, 'data' => $data, 'error' => '', 'raw' => $res);
}

/** 接続確認：自分が誰としてログインできているかを見る */
function wpp_check($conf) {
    if (trim($conf['wp_url']) === '') { return array('ok' => false, 'error' => 'サイトURLが未設定です'); }
    $r = wpp_api($conf, 'users/me?context=edit');
    if (!$r['ok']) {
        $hint = '';
        if ($r['code'] === 401) { $hint = 'ユーザー名かアプリケーションパスワードが違います。'; }
        if ($r['code'] === 403) { $hint = 'セキュリティプラグイン（WAF）に弾かれている可能性があります。'; }
        if ($r['code'] === 404) { $hint = 'REST APIが無効か、URLが違います。'; }
        return array('ok' => false, 'error' => "HTTP {$r['code']}｜{$r['error']}　{$hint}");
    }
    return array('ok' => true, 'name' => $r['data']['name'] ?? '', 'id' => $r['data']['id'] ?? 0);
}

/** カテゴリ・タグの「名前」を「ID」に変換する（無ければ作る） */
function wpp_term_id($conf, $taxonomy, $name, $create = true) {
    $name = trim($name);
    if ($name === '') { return null; }

    $r = wpp_api($conf, $taxonomy . '?per_page=100&search=' . rawurlencode($name));
    if ($r['ok'] && is_array($r['data'])) {
        foreach ($r['data'] as $t) {
            if (isset($t['name']) && $t['name'] === $name) { return (int)$t['id']; }
        }
    }
    if (!$create) { return null; }

    $r2 = wpp_api($conf, $taxonomy, 'POST',
        json_encode(array('name' => $name), JSON_UNESCAPED_UNICODE),
        array('Content-Type: application/json'));
    if ($r2['ok'] && isset($r2['data']['id'])) { return (int)$r2['data']['id']; }
    // 既に存在する場合、エラーの中にIDが入って返ってくることがある
    if (isset($r2['data']['data']['term_id'])) { return (int)$r2['data']['data']['term_id']; }
    return null;
}

/** 画像を送ってメディアIDをもらう（アイキャッチ用） */
function wpp_upload_image($conf, $image_url, $filename = '') {
    if (!preg_match('#^https?://#i', $image_url)) {
        return array('ok' => false, 'error' => '画像URLの形式が正しくありません');
    }
    // allow_url_fopen が無効なサーバーでも動くよう curl で取得する
    $ch = curl_init($image_url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; WPPoster/1.0)',
    ));
    $img  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype= (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($img === false || $code < 200 || $code >= 300) {
        return array('ok' => false, 'error' => "画像を取得できませんでした（HTTP {$code}）{$cerr}");
    }
    if (strlen($img) > 8 * 1024 * 1024) {
        return array('ok' => false, 'error' => '画像が大きすぎます（8MBまで）');
    }
    if (stripos($ctype, 'image/') !== 0) {
        return array('ok' => false, 'error' => '画像ではないファイルです（' . $ctype . '）');
    }

    if ($filename === '') {
        $filename = basename((string)parse_url($image_url, PHP_URL_PATH));
    }
    // ファイル名は英数字・ハイフン・アンダースコア・ドットだけに整える
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '', $filename);
    if ($filename === '' || strpos($filename, '.') === false) { $filename = 'image.jpg'; }

    $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mime = array('jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp');
    $type = $mime[$ext] ?? $ctype;

    $r = wpp_api($conf, 'media', 'POST', $img, array(
        'Content-Type: ' . $type,
        'Content-Disposition: attachment; filename="' . $filename . '"',
    ));
    if (!$r['ok']) { return array('ok' => false, 'error' => "画像送信 HTTP {$r['code']}｜{$r['error']}"); }
    return array('ok' => true, 'id' => (int)($r['data']['id'] ?? 0), 'url' => $r['data']['source_url'] ?? '');
}

/**
 * 記事を投稿する。
 * $item = array(
 *   'key'=>重複判定用の識別子, 'title'=>, 'content'=>, 'status'=>,
 *   'categories'=>array('名前'), 'tags'=>array('名前'),
 *   'slug'=>, 'image_url'=>
 * )
 */
function wpp_post_article($conf, $item) {
    $key = (string)($item['key'] ?? '');
    if ($key !== '' && wpp_is_done($key)) {
        $done = wpp_done();
        return array('ok' => true, 'skipped' => true,
                     'message' => '投稿済みのため飛ばしました',
                     'post_id' => $done[$key]['post_id'] ?? 0,
                     'link'    => $done[$key]['link'] ?? '');
    }

    $status = $item['status'] ?? ($conf['default_status'] ?? 'draft');
    if (!in_array($status, array('draft', 'publish', 'pending', 'private'), true)) { $status = 'draft'; }

    $payload = array(
        'title'   => (string)($item['title'] ?? '無題'),
        'content' => (string)($item['content'] ?? ''),
        'status'  => $status,
    );
    if (!empty($item['slug']))    { $payload['slug'] = $item['slug']; }
    if (!empty($item['excerpt'])) { $payload['excerpt'] = $item['excerpt']; }

    // カテゴリ・タグは名前 → ID に変換して送る
    foreach (array('categories' => 'categories', 'tags' => 'tags') as $field => $tax) {
        if (empty($item[$field])) { continue; }
        $ids = array();
        foreach ((array)$item[$field] as $name) {
            $id = wpp_term_id($conf, $tax, $name);
            if ($id) { $ids[] = $id; }
        }
        if ($ids) { $payload[$field] = $ids; }
    }

    // アイキャッチ画像
    $img_note = '';
    if (!empty($item['image_url'])) {
        $up = wpp_upload_image($conf, $item['image_url']);
        if ($up['ok'] && $up['id']) { $payload['featured_media'] = $up['id']; $img_note = '画像OK'; }
        else { $img_note = '画像NG: ' . ($up['error'] ?? ''); }
    }

    $r = wpp_api($conf, 'posts', 'POST',
        json_encode($payload, JSON_UNESCAPED_UNICODE),
        array('Content-Type: application/json'));

    if (!$r['ok']) {
        $hint = '';
        if ($r['code'] === 403) { $hint = ' ※本文にscriptタグやハイフンの連続が含まれていると、WAFに弾かれることがあります。'; }
        wpp_log("投稿失敗 HTTP {$r['code']} | {$r['error']}");
        return array('ok' => false, 'error' => "HTTP {$r['code']}｜{$r['error']}{$hint}");
    }

    $post_id = (int)($r['data']['id'] ?? 0);
    $link    = $r['data']['link'] ?? '';
    if ($key !== '') {
        wpp_mark_done($key, array('post_id' => $post_id, 'link' => $link, 'at' => date('c'), 'title' => $payload['title']));
    }
    wpp_log("投稿成功 ID{$post_id} {$payload['title']} {$img_note}");

    return array('ok' => true, 'skipped' => false, 'post_id' => $post_id, 'link' => $link, 'image' => $img_note);
}

function wpp_log($line) {
    wpp_boot();
    $dir = WPP_DATA . '/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    @file_put_contents($dir . '/' . date('Y-m-d') . '.log', '[' . date('H:i:s') . '] ' . $line . "\n", FILE_APPEND);
}

function wpp_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** data/ を外から見せないための .htaccess（Apache 2.2 / 2.4 両対応） */
function wpp_deny_rule() {
    return "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
         . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
}

/** フォーム偽装（CSRF）対策のトークン */
function wpp_token() {
    if (empty($_SESSION['wpp_token'])) {
        $_SESSION['wpp_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['wpp_token'];
}

function wpp_token_field() {
    return '<input type="hidden" name="_t" value="' . wpp_e(wpp_token()) . '">';
}

function wpp_token_ok() {
    $t = $_POST['_t'] ?? '';
    return is_string($t) && $t !== '' && !empty($_SESSION['wpp_token'])
        && hash_equals($_SESSION['wpp_token'], $t);
}
