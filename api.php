<?php
/**
 * api.php — সাধারণ Key/Value স্টোরেজ ব্যাকএন্ড (কোনো ডাটাবেজ ইনস্টল ছাড়াই কাজ করে)
 * ------------------------------------------------------------------
 * এটি data/store.json ফাইলে সমস্ত তথ্য JSON আকারে সংরক্ষণ করে।
 * যেকোনো সাধারণ শেয়ারড হোস্টিং / cPanel / VPS-এ PHP 7.4+ থাকলেই চলবে।
 *
 * এন্ডপয়েন্ট:
 *   GET  api.php?action=get&key=xxx        -> { success, value }  (value = string|null)
 *   GET  api.php?action=list&prefix=xxx    -> { success, keys: [] }
 *   POST api.php  {action:'set', key, value}     -> { success }
 *   POST api.php  {action:'delete', key}         -> { success }
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ------------------------------------------------------------------
// প্রয়োজনে এখানে ব্যবহারকারীর নাম/পাসওয়ার্ড (বেসিক অথ) চালু করতে পারেন।
// এখন সংরক্ষণ ফাইলটি ডিরেক্টরি-লিস্টিং ও সরাসরি ওয়েব-অ্যাক্সেস থেকে সুরক্ষিত (নিচে data/.htaccess দেখুন)।
// ------------------------------------------------------------------

const DATA_DIR  = __DIR__ . '/data';
const DATA_FILE = DATA_DIR . '/store.json';
const MAX_KEY_LEN   = 200;
const MAX_VALUE_LEN = 5 * 1024 * 1024; // 5MB, artifacts storage API-এর সাথে সামঞ্জস্যপূর্ণ

function fail($msg, $code = 400){
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function valid_key($key){
    if(!is_string($key) || $key === '') return false;
    if(strlen($key) > MAX_KEY_LEN) return false;
    // whitespace, path separators, quotes নিষিদ্ধ (storage API-এর নিয়ম অনুসরণ করে)
    if(preg_match('/[\s\/\\\\\'"]/', $key)) return false;
    return true;
}

function ensure_store(){
    if(!is_dir(DATA_DIR)){
        if(!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)){
            fail('data ডিরেক্টরি তৈরি করা যায়নি। ফোল্ডার পারমিশন পরীক্ষা করুন।', 500);
        }
    }
    if(!file_exists(DATA_FILE)){
        file_put_contents(DATA_FILE, json_encode(new stdClass()));
    }
}

/** ফাইল লক করে নিরাপদে পড়া/লেখা */
function with_store(callable $fn){
    ensure_store();
    $fp = fopen(DATA_FILE, 'c+');
    if(!$fp) fail('স্টোরেজ ফাইল খোলা যায়নি।', 500);
    if(!flock($fp, LOCK_EX)){
        fclose($fp);
        fail('স্টোরেজ ফাইল লক করা যায়নি।', 500);
    }
    $raw = stream_get_contents($fp);
    $store = json_decode($raw, true);
    if(!is_array($store)) $store = [];

    $result = $fn($store, $fp);

    fclose($fp);
    return $result;
}

$action = $_GET['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

if($method === 'GET'){
    if($action === 'get'){
        $key = $_GET['key'] ?? '';
        if(!valid_key($key)) fail('অবৈধ key।');
        $value = with_store(function(&$store) use ($key){
            return array_key_exists($key, $store) ? $store[$key] : null;
        });
        echo json_encode(['success' => true, 'value' => $value], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if($action === 'list'){
        $prefix = $_GET['prefix'] ?? '';
        $keys = with_store(function(&$store) use ($prefix){
            $out = [];
            foreach(array_keys($store) as $k){
                if($prefix === '' || strpos($k, $prefix) === 0) $out[] = $k;
            }
            return $out;
        });
        echo json_encode(['success' => true, 'keys' => $keys], JSON_UNESCAPED_UNICODE);
        exit;
    }

    fail('অজানা action।');
}

if($method === 'POST'){
    $bodyRaw = file_get_contents('php://input');
    $body = json_decode($bodyRaw, true);
    if(!is_array($body)) fail('অবৈধ JSON বডি।');

    $action = $body['action'] ?? null;

    if($action === 'set'){
        $key = $body['key'] ?? '';
        $value = $body['value'] ?? null;
        if(!valid_key($key)) fail('অবৈধ key।');
        if(!is_string($value)) fail('value অবশ্যই string (JSON.stringify করা) হতে হবে।');
        if(strlen($value) > MAX_VALUE_LEN) fail('value আকার সীমা অতিক্রম করেছে (৫MB)।');

        with_store(function(&$store, $fp) use ($key, $value){
            $store[$key] = $value;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($fp);
        });

        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if($action === 'delete'){
        $key = $body['key'] ?? '';
        if(!valid_key($key)) fail('অবৈধ key।');

        with_store(function(&$store, $fp) use ($key){
            unset($store[$key]);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($fp);
        });

        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    fail('অজানা action।');
}

fail('অসমর্থিত মেথড।', 405);
