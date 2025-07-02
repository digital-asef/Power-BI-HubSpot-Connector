<?php

/* #########################
* This code was developed by:
* Audox Ingeniería SpA.
* website: www.audox.com
* email: info@audox.com
######################### */

/**
 * Fallback for getallheaders() on CGI hosts like InfinityFree
 */
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

/**
 * Authenticates the user based on the provided headers.
 */
function auth($headers) {
    $headers = array_change_key_case($headers);
    $valid_tokens = ['FREETOKEN', 'TOKEN1', 'TOKEN2'];

    // Check Authorization header
    if (isset($headers["authorization"])) {
        list($type, $authorization) = explode(" ", $headers["authorization"]);
        if ($type === "Bearer" && in_array($authorization, $valid_tokens)) {
            return true;
        }
    }

    // Fallback: Check token from URL parameter
    if (isset($_GET['token']) && in_array($_GET['token'], $valid_tokens)) {
        return true;
    }

    return false;
}
/**
 * Fetch records from the HubSpot API.
 */
function get_records($object, $params) {
    $hubspot_key = $params['hapikey'];
    unset($params['hapikey']);

    $url = 'https://api.hubapi.com/crm/v3/';
    if (in_array($object, ['companies', 'contacts', 'deals', 'meetings', 'calls', 'tasks', 'tickets'])) {
        $url .= 'objects/';
    }

    $url .= $object . '?' . http_build_query($params);

    $headers = [
        'Authorization:Bearer ' . $hubspot_key,
        'Content-Type:application/json',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $output = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($output, true);
    if (!is_array($result)) {
        return [['error' => 'Failed to fetch data', 'details' => $output]];
    }

    $records = [];

    if (!empty($result['results'])) {
        foreach ($result['results'] as $record) {
            if (in_array($object, ["deals", "contacts"]) && isset($record['associations']['companies'])) {
                $associations = $record['associations']['companies']['results'];
                foreach ($associations as $association) {
                    if (($object == "deals" && $association['type'] == "deal_to_company") ||
                        ($object == "contacts" && $association['type'] == "contact_to_company")) {
                        $record['properties']['company_id'] = $association['id'];
                        break;
                    }
                }
            }
            $records[] = $record;
        }
    }

    if (!empty($result['paging'])) {
        $params['hapikey'] = $hubspot_key;
        $params["after"] = $result['paging']['next']['after'];
        $records = array_merge($records, get_records($object, $params));
    }

    return $records;
}

/**
 * Main execution handler.
 */
function main(array $args) {
    $headers = isset($args['http']['headers']) ? $args['http']['headers'] : getallheaders();

    if (function_exists('auth') && !auth($headers)) {
        $error = json_encode(["error_code" => "401", "error_description" => "Unauthorized"]);
        return isset($args['http']['headers']) ? ["body" => $error] : print($error);
    }

    $params = array_filter($args, 'is_scalar');

    foreach(['action', 'object'] as $param){
        ${$param} = isset($params[$param]) ? $params[$param] : null;
        unset($params[$param]);
    }

    if ($action === "getRecords") {
        if (isset($params['properties']) && $params['properties'] === "*") {
            $properties = get_records("properties/{$object}", $params);
            $params['properties'] = implode(",", array_column($properties, 'name'));
        }
        $result = json_encode(get_records($object, $params));
    } else {
        $result = json_encode(["error" => "Invalid action"]);
    }

    return isset($args['http']['headers']) ? ["body" => $result] : print($result);
}

header('Content-Type: application/json');
http_response_code(200);
main($_REQUEST);
?>

