<?php
set_time_limit(0); // Allow long-running script

if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }
}

// Basic token-based security
function auth($headers) {
    $headers = array_change_key_case($headers);
    $valid_tokens = ['FREETOKEN', 'TOKEN1', 'TOKEN2'];

    if (isset($headers["authorization"])) {
        list($type, $authorization) = explode(" ", $headers["authorization"]);
        if ($type === "Bearer" && in_array($authorization, $valid_tokens)) {
            return true;
        }
    }

    if (isset($_GET['token']) && in_array($_GET['token'], $valid_tokens)) {
        return true;
    }

    return false;
}

// Main function: Fetch paginated records
function get_records($object, $params, $max_records = 50000) {
    $hubspot_key = $params['hapikey'];
    unset($params['hapikey']);

    $url_base = 'https://api.hubapi.com/crm/v3/objects/' . $object;
    $headers = [
        'Authorization:Bearer ' . $hubspot_key,
        'Content-Type:application/json',
    ];

    $records = [];
    $after = null;
    $fetched = 0;

    do {
        $params['limit'] = 100; // HubSpot max
        if ($after) $params['after'] = $after;

        $url = $url_base . '?' . http_build_query($params);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes
        $output = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($output, true);
        if (!isset($result['results'])) break;

        $records = array_merge($records, $result['results']);
        $fetched += count($result['results']);
        $after = $result['paging']['next']['after'] ?? null;

        sleep(1); // Be nice to HubSpot
    } while ($after && $fetched < $max_records);

    return $records;
}

// Handle incoming request
function main(array $args) {
    $headers = isset($args['http']['headers']) ? $args['http']['headers'] : getallheaders();
    if (!auth($headers)) {
        $error = json_encode(["error_code" => "401", "error_description" => "Unauthorized"]);
        return isset($args['http']['headers']) ? ["body" => $error] : print($error);
    }

    $params = array_filter($args, 'is_scalar');
    $action = $params['action'] ?? null;
    $object = $params['object'] ?? null;
    $max_records = isset($params['max_records']) ? intval($params['max_records']) : 50000;
    $cached = isset($params['cached']) ? true : false;
    unset($params['action'], $params['object'], $params['max_records'], $params['cached']);

    $cache_file = __DIR__ . "/cache/{$object}.json";

    if ($action === "getRecords") {
        if ($cached && file_exists($cache_file)) {
            echo file_get_contents($cache_file);
            return;
        }

        $data = get_records($object, $params, $max_records);
        if (!is_dir("cache")) mkdir("cache");
        file_put_contents($cache_file, json_encode($data));
        echo json_encode($data);
        return;
    }

    echo json_encode(["error" => "Invalid action"]);
}

header('Content-Type: application/json');
http_response_code(200);
main($_REQUEST);
