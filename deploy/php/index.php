<?php
declare(strict_types=1);
require_once __DIR__ . '/kp_service.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$configPath = dirname(__DIR__, 2) . '/ai_seller_config.php';
if (!is_file($configPath)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'config_missing']);
    exit;
}
$config = require $configPath;

function respond($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_payload() {
    $type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (strpos($type, 'application/json') !== false) {
        $data = json_decode(file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

function ensure_data_dir($config) {
    $dir = $config['data_dir'];
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create data directory');
    }
    return $dir;
}

function log_event($config, $record) {
    $dir = ensure_data_dir($config);
    $record['recorded_at'] = gmdate('c');
    file_put_contents($dir . '/events.jsonl', json_encode($record, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

function state_path($config, $conversationId) {
    return ensure_data_dir($config) . '/state_' . hash('sha256', $conversationId) . '.json';
}

function load_state($config, $conversationId) {
    $path = state_path($config, $conversationId);
    if (!is_file($path)) return ['facts' => []];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : ['facts' => []];
}

function save_state($config, $conversationId, $state) {
    file_put_contents(state_path($config, $conversationId), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function bool_value($value) {
    return $value === true || $value === 1 || $value === '1' || $value === 'Y' || $value === 'true';
}

// Сообщение, которое отправил живой менеджер прямо из мессенджера (MAX и другие
// каналы), возвращается в Битрикс через того же коннектора, что и сообщения
// клиента. Отличить его можно только по служебной пометке канала. Без этой
// проверки бот принимает ответ менеджера за реплику клиента и отвечает на него.
function operator_takeover_message($text) {
    $text = (string)$text;
    if (mb_strpos($text, 'Отправки:') === false) return false;
    return mb_strpos($text, 'Исходящее сообщение') !== false
        || mb_strpos($text, 'Прочитано') !== false;
}

function bot_disabled_flag_path($config) {
    return $config['data_dir'] . '/bot_disabled.flag';
}

function bot_globally_disabled($config) {
    return is_file(bot_disabled_flag_path($config));
}

function event_key($eventData) {
    $message = isset($eventData['message']) && is_array($eventData['message']) ? $eventData['message'] : [];
    if (!empty($message['id'])) return 'message:' . (string)$message['id'];
    if (!empty($message['uuid'])) return 'uuid:' . (string)$message['uuid'];
    return '';
}

function claim_event($config, $key) {
    if ($key === '') return false;
    $dir = ensure_data_dir($config) . '/processed';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) return false;
    $path = $dir . '/' . hash('sha256', $key) . '.done';
    $handle = @fopen($path, 'x');
    if ($handle === false) return false;
    fwrite($handle, gmdate('c'));
    fclose($handle);
    return true;
}

function add_history($state, $role, $text) {
    if (!isset($state['history']) || !is_array($state['history'])) $state['history'] = [];
    if (trim($text) !== '') $state['history'][] = ['role' => $role, 'text' => mb_substr(trim($text), 0, 1000)];
    $state['history'] = array_slice($state['history'], -12);
    if ($role === 'user') $state['client_message_count'] = (int)($state['client_message_count'] ?? 0) + 1;
    return $state;
}

function extract_facts($text, $state) {
    $low = mb_strtolower($text, 'UTF-8');
    if (!isset($state['facts'])) $state['facts'] = [];
    $conversationText = $low;
    foreach ((array)($state['history'] ?? []) as $historyItem) {
        if (($historyItem['role'] ?? '') !== 'user') continue;
        $conversationText .= ' ' . mb_strtolower((string)($historyItem['text'] ?? ''), 'UTF-8');
    }
    if (empty($state['facts']['request']) && trim($text) !== '') $state['facts']['request'] = mb_substr($text, 0, 500);
    $cities = ['казань', 'москва', 'самара', 'тольятти', 'краснодар', 'уфа', 'санкт-петербург', 'екатеринбург', 'новосибирск'];
    foreach ($cities as $city) {
        if (mb_strpos($low, $city) !== false) $state['facts']['city'] = mb_convert_case($city, MB_CASE_TITLE, 'UTF-8');
    }
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:м|см)?\s*(?:x|х|×|на)\s*(\d+(?:[.,]\d+)?)\s*(м|см)?/u', $low, $m)) {
        $newSize = $m[1] . ' × ' . $m[2] . (!empty($m[3]) ? ' ' . $m[3] : '');
        if (!empty($state['facts']['size']) && $state['facts']['size'] !== $newSize) {
            $state['facts']['previous_size'] = $state['facts']['size'];
        }
        $state['facts']['size'] = $newSize;
    }
    if (mb_strpos($low, 'панно') !== false) $state['facts']['product'] = 'панно';
    if (mb_strpos($low, 'логотип') !== false || mb_strpos($low, 'лого') !== false) $state['facts']['product'] = 'панно с логотипом';
    if (mb_strpos($low, 'фото стены') !== false || mb_strpos($low, 'фото объекта') !== false) $state['facts']['expects_object_photo'] = true;
    if (preg_match('/\b(два|2)\s+панно/u', $low)) $state['facts']['quantity'] = 2;
    if (mb_strpos($low, 'без рам') !== false || preg_match('/рам[ау]?\s+(?:не\s+нуж|не\s+над)/u', $low)) {
        $state['facts']['frame'] = 'без рамы';
    } elseif (preg_match('/(?:с\s+рам|в\s+рам|нужн[а-я]*\s+(?:\w+\s+){0,2}рам|рам[ау]?\s+нуж)/u', $low)) {
        $state['facts']['frame'] = 'нужна';
    } elseif (mb_strpos($low, 'рам') !== false && empty($state['facts']['frame'])) {
        $state['facts']['frame'] = 'обсуждается';
    }
    if (mb_strpos($low, 'без подсвет') !== false || preg_match('/подсветк[аиу]?\s+(?:не\s+нуж|не\s+над)/u', $low)) {
        $state['facts']['lighting'] = 'без подсветки';
    } elseif (preg_match('/(?:с|нужн[а-я]*)\s+(?:\w+\s+){0,2}подсвет|подсветк[аиу]?\s+нуж/u', $low)) {
        $state['facts']['lighting'] = 'нужна';
    } elseif (mb_strpos($low, 'подсвет') !== false && empty($state['facts']['lighting'])) {
        $state['facts']['lighting'] = 'обсуждается';
    }
    if (preg_match('/бюджет[^\d]{0,12}(\d[\d\s]*(?:[.,]\d+)?(?:\s*(?:тыс|т|к))?)/u', $low, $budget)) {
        $state['facts']['budget'] = trim($budget[1]);
    }
    foreach (['сколько стоит', 'скажите цену', 'сначала цену', 'примерный бюджет'] as $phrase) {
        if (mb_strpos($low, $phrase) !== false) $state['route'] = 'price_first';
    }
    if (preg_match(
        '/нет\s+(?:фото|фотографи[ия])\s+стен|(?:фото|фотографи[ия])\s+стен[ыа]\s+(?:пока\s+)?нет|' .
        'стен[аы]\s+(?:ещ[её]\s+)?не\s+готов/u',
        $conversationText
    )) {
        $state['facts']['object_photo_unavailable'] = true;
        unset($state['facts']['expects_object_photo']);
    }
    if (preg_match('/^\s*(?:не\s+могу|не\s+получится)\s*[.!)]*\s*$/u', $low)) {
        $history = (array)($state['history'] ?? []);
        $lastAssistant = '';
        for ($index = count($history) - 1; $index >= 0; $index--) {
            if (($history[$index]['role'] ?? '') === 'assistant') {
                $lastAssistant = mb_strtolower((string)($history[$index]['text'] ?? ''), 'UTF-8');
                break;
            }
        }
        if (preg_match('/фото\s+стен|прислать\s+фото/u', $lastAssistant)) {
            $state['facts']['object_photo_unavailable'] = true;
            unset($state['facts']['expects_object_photo']);
        }
    }
    return $state;
}

function decide_stage($state, $hasObjectPhoto) {
    if ($hasObjectPhoto) return 'visual';
    if (isset($state['route']) && $state['route'] === 'price_first' && !empty($state['facts']['size']) && !empty($state['facts']['product'])) return 'calc';
    if (!empty($state['facts']['request'])) return 'needs';
    return 'new';
}

function fallback_reply($state, $hasObjectPhoto, $hasUnknownImage, $lastText = '') {
    $lastLow = mb_strtolower((string)$lastText, 'UTF-8');
    if (!empty($state['facts']['object_photo_unavailable']) &&
        preg_match('/не\s+могу|нет\s+(?:фото|фотограф)|не\s+готов/u', $lastLow)) {
        return 'Поняла) Тогда без фото стены. Пока можем подобрать вариант по готовым работам)';
    }
    if (preg_match('/покаж|пришл|фото\s+пример|пример(?:ы|ов|ами|а)\b|вариант(?:ы|ов)\b/u', $lastLow)) {
        return 'Да, конечно) Сейчас пришлю несколько примеров, чтобы было проще выбрать)';
    }
    if ($hasObjectPhoto && empty($state['facts']['city'])) return 'Спасибо) Как срочно планируется панно и какой у вас город?';
    if ($hasObjectPhoto) return 'Спасибо) Подскажите, пожалуйста, как срочно планируется панно?';
    if ($hasUnknownImage) return 'Спасибо) Подскажите, пожалуйста, это фото стены, на которой планируете разместить панно, или пример, который вам нравится?';
    if (empty($state['facts']['product'])) return 'Добрый день) Меня зовут Алина, студия премиум озеленения Eco-Store🌿 Какое изделие вы рассматриваете?';
    if (empty($state['facts']['size'])) return 'Подскажите, пожалуйста, примерный размер изделия? Тогда я смогу сориентировать по вариантам)';
    if (!empty($state['facts']['frame']) && !empty($state['facts']['lighting'])) {
        return 'Поняла) А можете прислать фото стены, где будет панно? Покажем, как будет смотреться, и посчитаем)';
    }
    if (empty($state['facts']['frame']) && empty($state['facts']['lighting'])) {
        $size = mb_strtolower((string)$state['facts']['size'], 'UTF-8');
        if (preg_match('/^(?:100\s*×\s*100\s*см|1\s*×\s*1\s*м)$/u', $size)) {
            return 'Стоимость панно 100 на 100 см начинается от 12 500 рублей) Подскажите, рама и подсветка нужны?';
        }
        return 'Стоимость рассчитывается индивидуально по наполнению и комплектации) Подскажите, рама и подсветка нужны?';
    }
    if (empty($state['facts']['frame'])) return 'Поняла) А рама нужна?';
    if (empty($state['facts']['lighting'])) return 'Поняла) А подсветка нужна?';
    return 'А можете прислать фото стены, где будет панно? Покажем, как будет смотреться, и посчитаем)';
}

function decode_model_json($content) {
    if (is_array($content)) {
        $parts = [];
        foreach ($content as $item) {
            if (is_array($item) && isset($item['text'])) $parts[] = (string)$item['text'];
            elseif (is_string($item)) $parts[] = $item;
        }
        $content = implode("\n", $parts);
    }
    $content = trim((string)$content);
    $content = preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', $content);
    $parsed = json_decode($content, true);
    if (is_array($parsed)) return $parsed;
    $start = strpos($content, '{');
    $end = strrpos($content, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $parsed = json_decode(substr($content, $start, $end - $start + 1), true);
        if (is_array($parsed)) return $parsed;
    }
    return [];
}

function sanitize_reply($reply, $state, $fallback) {
    $reply = trim((string)$reply);
    if ($reply === '' || !mb_check_encoding($reply, 'UTF-8') || preg_match('/\?{4,}/', $reply)) {
        return $fallback;
    }
    $reply = str_replace(['—', '–'], ', ', $reply);
    $reply = preg_replace('/\s+/u', ' ', $reply);
    $messageCount = (int)($state['client_message_count'] ?? 0);
    if ($messageCount > 1 && preg_match('/меня зовут\s+алина|студия\s+(?:премиум|премиального)\s+озеленения/iu', $reply)) {
        return $fallback;
    }
    if ($messageCount > 1) {
        $reply = preg_replace('/^(?:добрый\s+(?:день|вечер)|здравствуйте|привет)[!,.()\s]*/iu', '', $reply);
        $reply = trim($reply);
    }
    return mb_substr($reply !== '' ? $reply : $fallback, 0, 700, 'UTF-8');
}

function reply_repeats_history($reply, $state) {
    $normalize = function ($value) {
        $value = mb_strtolower(strip_tags((string)$value), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    };
    $candidate = $normalize($reply);
    if (mb_strlen($candidate, 'UTF-8') < 12) return false;
    $assistants = [];
    foreach (array_reverse((array)($state['history'] ?? [])) as $item) {
        if (($item['role'] ?? '') !== 'assistant') continue;
        $assistants[] = $normalize($item['text'] ?? '');
        if (count($assistants) >= 6) break;
    }
    foreach ($assistants as $previous) {
        if ($previous === '') continue;
        if (hash_equals($previous, $candidate)) return true;
        similar_text($previous, $candidate, $percent);
        if ($percent >= 88) return true;
    }
    return false;
}

function reply_is_incomplete($reply) {
    $clean = trim(preg_replace('/[)\s😊🌿]+$/u', '', (string)$reply));
    if ($clean === '') return true;
    return (bool)preg_match('/\b(?:и|а|но|или|без|с|в|на|для|чтобы|зависит|стоимость|точнее)$/iu', $clean);
}

function structured_safe_reply($state, $fallback) {
    $facts = (array)($state['facts'] ?? []);
    if (!empty($facts['product']) && !empty($facts['size'])) {
        $parts = [(string)$facts['product'] . ' ' . (string)$facts['size']];
        if (($facts['frame'] ?? '') === 'нужна') $parts[] = 'в раме';
        elseif (($facts['frame'] ?? '') === 'без рамы') $parts[] = 'без рамы';
        if (($facts['lighting'] ?? '') === 'нужна') $parts[] = 'с подсветкой';
        elseif (($facts['lighting'] ?? '') === 'без подсветки') $parts[] = 'без подсветки';
        $reply = 'Поняла) Считаем ' . implode(', ', $parts) . '.';
        if (empty($facts['city'])) $reply .= ' Какой у вас город?';
        elseif (empty($state['kp_delivered_at'])) $reply .= ' К какой дате нужно изделие?';
        return $reply;
    }
    return $fallback;
}

function knowledge_search($config, $query, $limit = 4) {
    $path = $config['data_dir'] . '/knowledge.json';
    if (!is_file($path)) return [];
    $items = json_decode(file_get_contents($path), true);
    if (!is_array($items)) return [];
    $curatedPath = $config['data_dir'] . '/curated_rules.json';
    if (is_file($curatedPath)) {
        $curated = json_decode(file_get_contents($curatedPath), true);
        if (is_array($curated)) $items = array_merge($curated, $items);
    }
    $queryLow = mb_strtolower($query, 'UTF-8');
    $topicHints = [];
    if (preg_match('/ухаж|уход|полив|моч|опрыск/u', $queryLow)) $topicHints[] = 'уход поливать опрыскивать мочить';
    if (preg_match('/достав|отправ|транспорт|сдэк|делов/u', $queryLow)) $topicHints[] = 'доставка отправляем СДЭК Деловыми линиями';
    if (preg_match('/монтаж|установ|креп|саморез/u', $queryLow)) $topicHints[] = 'монтаж крепления саморез видеоинструкция';
    if (preg_match('/дорог|дешев|скидк|конкурент/u', $queryLow)) $topicHints[] = 'дорого дешевле качество стабилизации гарантия';
    if (preg_match('/срок|успе|дат|готов/u', $queryLow)) $topicHints[] = 'срок дата успеем изготовление';
    if (preg_match('/стекл|кот|кош|животн/u', $queryLow)) $topicHints[] = 'оргстекло дом общественное пространство кот животное';
    if (preg_match('/налож|оплат|цел|повреж|страх|обреш/u', $queryLow)) $topicHints[] = 'оплата 20 80 доставка страховка обрешетка повреждение';
    $expandedQuery = trim($queryLow . ' ' . implode(' ', $topicHints));
    preg_match_all('/[\p{L}\p{N}]{3,}/u', $expandedQuery, $matches);
    $words = array_unique($matches[0]);
    $scored = [];
    foreach ($items as $item) {
        if (empty($item['text'])) continue;
        $low = mb_strtolower($item['text'], 'UTF-8');
        $score = 0;
        foreach ($words as $word) if (mb_strpos($low, $word) !== false) $score++;
        if ($score > 0) {
            $priority = (($item['source'] ?? '') === 'curated_rules.json' || !isset($item['source'])) ? 3 : 0;
            $scored[] = ['score' => $score + $priority, 'text' => $item['text']];
        }
    }
    usort($scored, function ($a, $b) { return $b['score'] <=> $a['score']; });
    return array_slice(array_column($scored, 'text'), 0, $limit);
}

function ai_reply($config, $state, $text, $knowledge, $fallback) {
    if (empty($config['llm_api_key'])) return ['text' => $fallback, 'needs_human' => false, 'source' => 'rules'];
    $textLow = mb_strtolower($text, 'UTF-8');
    $portfolioUrl = 'https://disk.yandex.ru/d/ZLLcoSlnI_N9Qw';
    if (!empty($state['kp_delivered_at']) &&
        preg_match('/^\s*(?:и\s+)?что\s+(?:это|за\s+(?:файл|расч[её]т|кп))\s*[?!.]*\s*$/u', $textLow)) {
        $sizeText = trim((string)($state['facts']['size'] ?? ''));
        $withoutFrame = (($state['facts']['frame'] ?? '') === 'без рамы');
        $withoutLight = (($state['facts']['lighting'] ?? '') === 'без подсветки');
        return [
            'text' => 'Это расчёт по последней выбранной фотографии: панно ' . $sizeText . ', ' .
                ($withoutFrame ? 'без рамы' : 'в раме') . ', ' .
                ($withoutLight ? 'без подсветки' : 'с подсветкой') .
                '. Внутри три варианта наполнения: Стандарт, Стандарт+ и Премиум)',
            'needs_human' => false,
            'reason' => 'explain_delivered_kp',
            'source' => 'rules',
        ];
    }
    if (preg_match('/^\s*(?:жесть|ужас|кошмар|капец|что\s+за\s+(?:бред|дичь))\s*[?!.]*\s*$/u', $textLow)) {
        return [
            'text' => 'Извините, я действительно запутала переписку. Передаю диалог менеджеру, чтобы дальше ответить вам нормально)',
            'needs_human' => true,
            'reason' => 'client_frustrated_transfer',
            'source' => 'rules',
        ];
    }
    preg_match_all('/(\d+(?:[.,]\d+)?)\s*(?:см|м)?\s*(?:×|x|х|на)\s*(\d+(?:[.,]\d+)?)\s*(см|м)?/u', $textLow, $sizeMatches, PREG_SET_ORDER);
    if (count($sizeMatches) > 1 && preg_match('/(?:счит|посчит|рассчит|цен|стоим)/u', $textLow)) {
        $labels = [];
        foreach ($sizeMatches as $sizeMatch) {
            $labels[] = $sizeMatch[1] . '×' . $sizeMatch[2] . (!empty($sizeMatch[3]) ? ' ' . $sizeMatch[3] : ' см');
        }
        return [
            'text' => 'Да, конечно) Посчитаю размеры ' . implode(' и ', $labels) . '. Соберу всё в одном КП и сейчас пришлю)',
            'needs_human' => false,
            'reason' => 'kp_multi_size_generate',
            'source' => 'rules',
            'sizes' => $labels,
        ];
    }
    $asksSeveralVariants = preg_match('/(?:оба|обе|два|две|несколько|все).{0,20}вариант/u', $textLow);
    $asksWithAndWithoutFrame =
        (mb_strpos($textLow, 'без рамы') !== false || mb_strpos($textLow, 'без рам') !== false) &&
        (mb_strpos($textLow, 'в раме') !== false || mb_strpos($textLow, 'с рамой') !== false);
    if (($asksSeveralVariants || $asksWithAndWithoutFrame) && mb_strpos($textLow, 'рам') !== false) {
        $sizeText = trim((string)($state['facts']['size'] ?? $state['pending_kp_confirmation']['size'] ?? ''));
        $lightText = (($state['facts']['lighting'] ?? '') === 'без подсветки') ? ', без подсветки' : '';
        return [
            'text' => 'Да, конечно) Посчитаю оба варианта: ' . $sizeText . ' в раме и без рамы' .
                $lightText . '. Сейчас пришлю одним КП)',
            'needs_human' => false,
            'reason' => 'kp_multi_generate',
            'source' => 'rules',
        ];
    }
    if (preg_match('/^\s*(?:продолжи|продолжайте|давайте продолжим|считайте дальше)\s*[.!)]*\s*$/u', $textLow)) {
        $recentText = '';
        foreach (array_slice((array)($state['history'] ?? []), -12) as $historyItem) {
            $recentText .= ' ' . mb_strtolower((string)($historyItem['text'] ?? ''), 'UTF-8');
        }
        if ((mb_strpos($recentText, 'оба вариант') !== false || mb_strpos($recentText, 'два вариант') !== false) &&
            mb_strpos($recentText, 'без рам') !== false && mb_strpos($recentText, 'рам') !== false) {
            $sizeText = trim((string)($state['facts']['size'] ?? ''));
            return [
                'text' => 'Да, продолжаю) Считаю ' . $sizeText .
                    ' в раме и без рамы, оба без подсветки. Сейчас пришлю одним КП)',
                'needs_human' => false,
                'reason' => 'kp_multi_generate',
                'source' => 'rules',
            ];
        }
    }
    if (!empty($state['pending_kp_confirmation']) &&
        preg_match('/(?:считайте|посчитайте|жду\s+расч[её]т)/u', $textLow)) {
        return [
            'text' => 'Подготовила предварительный расчёт в трёх вариантах) Посмотрите, какой вам ближе: Стандарт, Стандарт+ или Премиум?',
            'needs_human' => false,
            'reason' => 'kp_confirmed_generate',
            'source' => 'rules',
        ];
    }
    if (!empty($state['pending_kp_confirmation']) &&
        preg_match('/без\s+рам|с\s+рам|рам[ау]\s+(?:тоже\s+)?(?:не\s+над|не\s+нуж)/u', $textLow)) {
        $sizeText = trim((string)($state['facts']['size'] ?? $state['pending_kp_confirmation']['size'] ?? ''));
        $withoutFrame = (($state['facts']['frame'] ?? '') === 'без рамы');
        $withoutLight = (($state['facts']['lighting'] ?? '') === 'без подсветки');
        $parts = [];
        $parts[] = $withoutFrame ? 'без рамы' : 'в раме';
        $parts[] = $withoutLight ? 'без подсветки' : 'с подсветкой';
        return [
            'text' => 'Да, можно) Тогда считаем этот же вариант ' . $sizeText . ', ' .
                implode(', ', $parts) . ', верно?',
            'needs_human' => false,
            'reason' => 'selected_reference_confirm_configuration',
            'source' => 'rules',
        ];
    }
    if (!empty($state['recent_client_image']) &&
        preg_match('/\d+\s*(?:[×xх]|на)\s*\d+/u', $textLow) &&
        preg_match('/без\s+подсвет|с\s+подсвет|в\s+рам|без\s+рам|если/u', $textLow)) {
        $sizeText = trim((string)($state['facts']['size'] ?? ''));
        $withoutLight = !empty($state['facts']['lighting']) &&
            (string)$state['facts']['lighting'] === 'без подсветки';
        $lightText = $withoutLight ? 'без подсветки' : 'с подсветкой';
        return [
            'text' => 'Да, можно) Правильно, считаем этот же вариант ' . $sizeText .
                ', в раме, ' . $lightText . '?',
            'needs_human' => false,
            'reason' => 'selected_reference_confirm_configuration',
            'source' => 'rules',
        ];
    }
    if (!empty($state['pending_kp_confirmation']) &&
        preg_match('/^\s*(?:да|давайте|верно|правильно|всё верно|считайте|(?:просто\s+)?посчитайте|пришлите|нужна цена)\s*[.!)]*\s*$/u', $textLow)) {
        return [
            'text' => 'Подготовила предварительный расчёт в трёх вариантах) Посмотрите, какой вам ближе: Стандарт, Стандарт+ или Премиум?',
            'needs_human' => false,
            'reason' => 'kp_confirmed_generate',
            'source' => 'rules',
        ];
    }
    if (!empty($state['recent_client_image']) &&
        preg_match('/(?:вот|так|такой|такую|точно).*(?:цен|стоим|так\s*же)|(?:цен|стоим).*(?:вот|так|такой|такую)/u', $textLow)) {
        $sizeText = trim((string)($state['facts']['size'] ?? ''));
        $details = $sizeText !== '' ? ' в размере ' . $sizeText : '';
        $withoutFrame = (($state['facts']['frame'] ?? '') === 'без рамы');
        $withoutLight = (($state['facts']['lighting'] ?? '') === 'без подсветки');
        $parts = [];
        $parts[] = $withoutFrame ? 'без рамы' : 'в раме';
        $parts[] = $withoutLight ? 'без подсветки' : 'с подсветкой';
        return [
            'text' => 'Поняла) Считаю именно такой вариант' . $details . ', ' .
                implode(', ', $parts) . '. Сейчас пришлю расчёт)',
            'needs_human' => false,
            'reason' => 'kp_single_generate',
            'source' => 'rules',
        ];
    }
    $portfolioRequested = preg_match(
        '/(?:покаж|пришл|отправ).*(?:работ|пример|фото)|(?:больше|ещ[её]).*(?:работ|пример|фото)|(?:работ|пример).*(?:больше|ещ[её])/u',
        $textLow
    );
    if (!$portfolioRequested && preg_match('/^\s*можно\s+(?:фото|примеры?)(?:\s+работ)?\s*[?!.]*\s*$/u', $textLow)) {
        $portfolioRequested = true;
    }
    if (!$portfolioRequested && preg_match('/^\s*(?:жду|хорошо,\s*жду|давайте)\s*[.!)]*\s*$/u', $textLow)) {
        $recentHistory = array_slice((array)($state['history'] ?? []), -6);
        foreach (array_reverse($recentHistory) as $historyItem) {
            if (($historyItem['role'] ?? '') !== 'user') continue;
            $previousUserText = mb_strtolower((string)($historyItem['text'] ?? ''), 'UTF-8');
            if (preg_match('/(?:работ|пример|фото)/u', $previousUserText)) {
                $portfolioRequested = true;
            }
            break;
        }
    }
    if ($portfolioRequested) {
        return [
            'text' => 'Да, конечно) Здесь можно посмотреть больше наших работ: ' . $portfolioUrl .
                "\nОткройте «1. Микс мха», затем «Премиум». Там есть примеры с подсветкой)",
            'needs_human' => false,
            'reason' => 'portfolio_link_more_examples',
            'source' => 'rules',
        ];
    }
    if (!empty($state['facts']['object_photo_unavailable']) &&
        preg_match('/^\s*(?:не\s+могу|нет|не\s+получится)\s*[.!)]*\s*$/u', $textLow)) {
        return [
            'text' => 'Поняла) Тогда без фото стены. Пока можем подобрать вариант по готовым работам)',
            'needs_human' => false,
            'reason' => 'wall_photo_unavailable',
            'source' => 'rules',
        ];
    }
    if (preg_match('/я\s+же\s+писал|выше\s+(?:вы\s+)?прислал|пьян/u', $textLow)) {
        return [
            'text' => 'Да, вы правы, я повторилась. Фото стены больше не прошу)',
            'needs_human' => false,
            'reason' => 'acknowledge_repetition',
            'source' => 'rules',
        ];
    }
    if (preg_match('/это\s+бот|полн\w*\s+фигн|ответ\w*\s+по\s+круг|что\s+творит/u', $textLow)) {
        return [
            'text' => 'Да, ответы пошли по кругу, извините. Я уже поняла, что фото стены нет, больше его не прошу)',
            'needs_human' => true,
            'reason' => 'conversation_failure_acknowledged',
            'source' => 'rules',
        ];
    }
    if (preg_match('/подсветк/u', $textLow) &&
        preg_match('/что\s+есть|покаж|пример|фото/u', $textLow)) {
        return [
            'text' => 'Да) В этой папке есть примеры панно с подсветкой: ' . $portfolioUrl .
                "\nОткройте «1. Микс мха», затем «Премиум»)",
            'needs_human' => false,
            'reason' => 'portfolio_link_lighting',
            'source' => 'rules',
        ];
    }
    if (preg_match('/покаж|пришл|фото\s+пример|пример(?:ы|ов|ами|а)\b|вариант(?:ы|ов)\b/u', $textLow)) {
        return [
            'text' => 'Да, конечно) Здесь можно посмотреть наши работы: ' . $portfolioUrl .
                "\nОткройте «1. Микс мха», затем «Премиум»)",
            'needs_human' => false,
            'reason' => 'portfolio_link',
            'source' => 'rules',
        ];
    }
    if (!empty($state['facts']['object_photo_unavailable']) &&
        preg_match(
            '/нет\s+(?:фото|фотографи[ия])\s+стен|(?:фото|фотографи[ия])\s+стен[ыа]\s+нет|' .
            'стен[аы]\s+(?:ещ[её]\s+)?не\s+готов/u',
            $textLow
        )) {
        return [
            'text' => 'Поняла вас) Тогда пока можно посмотреть готовые работы здесь: ' . $portfolioUrl .
                "\nОткройте «1. Микс мха», затем «Премиум»)",
            'needs_human' => false,
            'reason' => 'portfolio_link_no_wall_photo',
            'source' => 'rules',
        ];
    }
    $asksPrice = preg_match('/сколько|цен|стоимост|бюджет|посчит|рассчит/u', $textLow);
    $size = mb_strtolower((string)($state['facts']['size'] ?? ''), 'UTF-8');
    if ($asksPrice && !empty($state['facts']['product']) &&
        preg_match('/^(?:100\s*[×xх]\s*100\s*см|1\s*[×xх]\s*1\s*м)$/u', $size)) {
        $missing = [];
        if (empty($state['facts']['frame'])) $missing[] = 'рама';
        if (empty($state['facts']['lighting'])) $missing[] = 'подсветка';
        $question = $missing ? ' Подскажите, ' . implode(' и ', $missing) . ' нужны?' : '';
        return [
            'text' => 'Стоимость панно 100×100 см начинается от 12 500 рублей)' . $question,
            'needs_human' => false,
            'reason' => 'canonical_price_100x100',
            'source' => 'rules',
        ];
    }
    if ($asksPrice && !empty($state['facts']['product']) && !empty($state['facts']['size'])) {
        $question = empty($state['facts']['lighting'])
            ? ' Подскажите, подсветка нужна?'
            : ' Я уточню комплектацию и посчитаю точнее)';
        return [
            'text' => 'Для такого размера стоимость зависит от наполнения и комплектации)' . $question,
            'needs_human' => false,
            'reason' => 'price_requires_configuration',
            'source' => 'rules',
        ];
    }
    if (preg_match('/дорог|у других дешевле|наш[её]л дешевле|конкурент/u', $textLow)) {
        return [
            'text' => 'Понимаю вас) Разница в фактуре, плотности, качестве стабилизации и гарантии. Мы используем мох собственной стабилизации и даём гарантию 5 лет. Если пришлёте их КП, сравним комплектацию и материалы, чтобы было понятно, за счёт чего отличается стоимость.',
            'needs_human' => false,
            'reason' => 'canonical_price_objection',
            'source' => 'rules',
        ];
    }
    // Шаг 1: Новое поведение при непонятной ситуации (большая загруженность)
    // Триггеры: клиент подозревает бота, раздражён, или бот явно не справляется
    $suspectsBot = preg_match('/это\s+бот|вы\s+бот|робот|автоответчик|вы\s+человек|издевательство|издеваетесь/u', $textLow);
    $isRaggedOrGrumpy = preg_match('/сколько\s+можно|долго|тупая|достали|не\s+отвечаете|бля|блядь|гов|нах|черт|сука|пошёл|убей/ui', $text);
    if (($suspectsBot || $isRaggedOrGrumpy) && empty($state['overload_asked_at'])) {
        $product = !empty($state['facts']['product']) ? $state['facts']['product'] : 'изделие';
        return [
            'text' => 'Извините, пожалуйста) Сейчас у нас очень большая загруженность. Подскажите, насколько срочно вам нужно ' . $product . '?',
            'needs_human' => false,
            'reason' => 'overload_step1',
            'source' => 'rules',
        ];
    }
    // Шаг 2: Если флаг уже стоит, это следующее сообщение клиента - отправить "вернусь к вам" и запросить оператора
    if (!empty($state['overload_asked_at']) && empty($state['paused_by_operator'])) {
        return [
            'text' => 'Поняла вас, спасибо) Как только отпущу текущих клиентов, сразу вернусь к вам.',
            'needs_human' => true,
            'reason' => 'overload_step2',
            'source' => 'rules',
        ];
    }
    // КРИТИЧЕСКОЕ ПРАВИЛО: Жёсткая проверка информации об оплате
    // Если клиент упоминает оплату, вернуть ПРАВИЛЬНУЮ информацию о схеме
    $asksAboutPayment = preg_match('/оплат|платёж|платеж|цен|стоимост|аванс|расчёт|считать|сколько стоит|как платить|рассчит/u', $textLow);
    $hasPaymentInHistory = false;
    foreach ((array)($state['history'] ?? []) as $item) {
        if (preg_match('/оплат|платёж|платеж|цен|стоимост|аванс|расчёт/u', mb_strtolower((string)($item['text'] ?? ''), 'UTF-8'))) {
            $hasPaymentInHistory = true;
            break;
        }
    }

    if (($asksAboutPayment || $hasPaymentInHistory) && preg_match('/(?:платеж|оплат|цен|стоимост|аванс)/u', $textLow)) {
        // Клиент спрашивает про оплату - вернуть правильный ответ о схеме
        return [
            'text' => 'Стоимость рассчитаем точно, когда согласуем все детали. Схема платежа: 20% аванс при оформлении, остаток при получении панно)',
            'needs_human' => false,
            'reason' => 'payment_scheme_corrected',
            'source' => 'rules_payment_guard',
        ];
    }
    $system = 'Ты продавец-консультант Eco-Store по имени Алина. Пиши естественно, тепло и уверенно. Обычно 1-3 коротких абзаца и один вопрос за раз. Не используй длинные тире, канцелярит, заголовки и списки без необходимости. Не повторяй уже известные данные. Не придумывай цены, скидки, сроки и характеристики. Цены только из калькулятора. Гарантия на ягель 5 лет. Старайся привести клиента к бесплатной визуализации, но если он просит цену, собери параметры для расчёта. Не дави и не унижай конкурентов. Верни только JSON с полями reply, needs_human, reason.';
    $system = <<<'PROMPT'
Ты Алина, менеджер студии премиального озеленения Eco-Store.

Пиши как живой человек в мессенджере: тепло, легко и уверенно. Используй короткие разговорные формулировки Алины: «Добрый день)», «Подскажите, пожалуйста», «Я рассчитаю😊», «Варианты есть)». Скобочки обязательны и должны выглядеть естественно. Уместен один эмодзи.

Ответ должен состоять из 1–3 коротких предложений и раскрывать одну небольшую мысль. Не используй дефисы, тире, заголовки, списки, канцелярит и официальный стиль. Не пиши «отличный выбор», «отличный формат», «зависит от нескольких факторов», «предпочтения по параметрам», «чтобы назвать точную сумму», «благодарю за обращение» или «готова помочь». Не хвали клиента без причины.

«Добрый день» и представление допустимы только в первом ответе диалога. В последующих сообщениях никогда не здоровайся повторно. Сначала внимательно прочитай историю и известные данные. Никогда не спрашивай повторно размер, город, количество, срок, бюджет, раму, подсветку или назначение, если клиент уже сообщил это раньше.

Не забывай прямые вопросы клиента. Отвечай поэтапно, но сохраняй незакрытые вопросы и постепенно закрывай их. За одно сообщение ответь на один важный вопрос или связанную пару вопросов и задай не более одного следующего вопроса.

Считай каждое новое сообщение продолжением текущего заказа, пока клиент явно не начал обсуждать другое изделие. Фразы «а если 50 на 40», «можно без рамы», «тогда без подсветки» изменяют прежнюю комплектацию, а не начинают диалог заново. Коротко подтверди обновлённый вариант и не возвращайся к старым вопросам.

Если клиент уже прислал или выбрал фотографию работы, композиция считается выбранной. Никогда не спрашивай клиента, какой там состав мха: это должна определить студия. После выбора фотографии уточняй только действительно недостающие параметры расчёта. Не проси фото стены, если клиент отказался от визуализации или хочет расчёт по готовой работе.

Визуализацию предлагай просто: «А можете прислать фото стены, где будет панно? Покажем, как будет смотреться, и посчитаем)». Не пиши про три бесплатные визуализации. Получив фото стены, сначала узнай город и срочность. Не обещай визуал сегодня или завтра, пока не знаешь, к какой дате клиенту нужно изделие.

Опирайся только на известные данные и фрагменты базы знаний. Никогда не придумывай материалы, оттенки, подложку, цену, скидку, срок, гарантию или условия. Не задавай вопрос о параметре, которого нет в известных данных или базе. Если данных недостаточно, задай конкретный вопрос, который двигает сделку к визуализации или расчёту.
Никогда не предлагай скидку, акцию, подарок или специальную цену, даже если такая фраза встретилась в базе знаний: старые акции могут быть неактуальны. Это разрешается только при наличии явного поля authorized_offer в известных данных текущей сделки.

Для панно из мха стоимость начинается от 12 500 рублей за квадратный метр композиции. Точная стоимость зависит от наполнения и комплектации. Если клиент спрашивает цену панно 1×1 м, сообщи стоимость от 12 500 рублей и уточни, нужны ли рама и подсветка, а также будет ли панно на ровной стене или в нише.
Не рассчитывай цену арифметически из ставки за квадратный метр: минимальная стоимость и комплектация зависят от изделия. Для панели 50×50 см из микса мха «Премиум» известный пример стоимости — 5 000 рублей. Для других размеров без готового расчёта сообщи только стартовую ставку и собери параметры.

ВАЖНО: Условия оплаты ВСЕГДА только один вариант — никаких других схем:
— 20% аванс при оформлении заказа
— 80% остаток при получении панно
Если клиент спрашивает про оплату, платежи, авансы, рассчёты или условия — сообщи ТОЛЬКО эту схему. Никогда не придумывай, не предлагай другие варианты, не говори про полную оплату сейчас или после готовности.

Верни только готовый текст сообщения клиенту. Не добавляй JSON, пояснения, подписи и кавычки вокруг ответа.
PROMPT;
    // Модели нужна карточка фактов и несколько последних реплик, а не весь объект
    // состояния. Раньше история уходила дважды: внутри state и отдельным блоком.
    $card = [];
    if (!empty($state['facts']) && is_array($state['facts'])) $card['известные_данные'] = $state['facts'];
    if (!empty($state['stage'])) $card['этап'] = $state['stage'];
    $card['сообщений_от_клиента'] = (int)($state['client_message_count'] ?? 0);
    if (!empty($state['kp_delivered_at'])) $card['расчёт_уже_отправлен'] = true;
    if (!empty($state['pending_kp_confirmation'])) $card['ждём_подтверждения_расчёта'] = true;

    $recent = isset($state['history']) && is_array($state['history'])
        ? array_slice($state['history'], -6) : [];
    $historyLines = [];
    foreach ($recent as $item) {
        $role = (($item['role'] ?? '') === 'assistant') ? 'Алина' : 'Клиент';
        $historyLines[] = $role . ': ' . mb_substr(trim((string)($item['text'] ?? '')), 0, 300, 'UTF-8');
    }

    $payload = [
        'model' => $config['llm_model'], 'temperature' => 0.2, 'max_tokens' => 400,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'system', 'content' => 'Известные данные: ' . json_encode($card, JSON_UNESCAPED_UNICODE)],
            ['role' => 'system', 'content' => "Последние сообщения:\n" . implode("\n", $historyLines)],
            ['role' => 'system', 'content' => 'Фрагменты базы знаний: ' . implode("\n\n", $knowledge)],
            ['role' => 'user', 'content' => $text],
        ],
    ];
    $url = rtrim($config['llm_base_url'], '/') . '/chat/completions';
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $config['llm_api_key']];
    if (!empty($config['llm_project_id'])) $headers[] = 'OpenAI-Project: ' . $config['llm_project_id'];
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 35,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($raw === false || $code < 200 || $code >= 300) return ['text' => $fallback, 'needs_human' => false, 'source' => 'rules_after_llm_error'];
    $response = json_decode($raw, true);
    $content = isset($response['choices'][0]['message']['content']) ? $response['choices'][0]['message']['content'] : '';
    $parsed = decode_model_json($content);
    if (is_array($parsed) && !empty($parsed['reply'])) {
        return ['text' => $parsed['reply'], 'needs_human' => !empty($parsed['needs_human']),
            'reason' => isset($parsed['reason']) ? $parsed['reason'] : '', 'source' => 'llm_json'];
    }
    $plain = trim((string)$content);
    $plain = preg_replace('/^```(?:text)?\s*|\s*```$/iu', '', $plain);
    if ($plain === '') {
        return ['text' => structured_safe_reply($state, $fallback), 'needs_human' => false,
            'source' => 'structured_after_empty_llm'];
    }
    return ['text' => $plain, 'needs_human' => false, 'reason' => '', 'source' => 'llm_text'];
}

function bitrix_call($config, $method, $params) {
    $url = rtrim($config['bitrix_webhook_base'], '/') . '/' . $method . '.json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE)]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['error' => 'curl', 'error_description' => $error, 'http_status' => $code];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ['error' => 'invalid_json', 'http_status' => $code];
}

function kp_size_from_state($state) {
    $size = mb_strtolower((string)($state['facts']['size'] ?? ''), 'UTF-8');
    if (!preg_match('/(\d+(?:[.,]\d+)?)\s*(?:см|м)?\s*(?:×|x|х|на)\s*(\d+(?:[.,]\d+)?)\s*(см|м)?/u', $size, $match)) {
        return [];
    }
    $width = (float)str_replace(',', '.', $match[1]);
    $height = (float)str_replace(',', '.', $match[2]);
    $unit = $match[3] ?? 'см';
    if ($unit === 'м') {
        $width *= 100;
        $height *= 100;
    }
    if ($width <= 0 || $height <= 0) return [];
    return ['width_cm' => $width, 'height_cm' => $height];
}

function kp_size_from_text($sizeText) {
    return kp_size_from_state(['facts' => ['size' => (string)$sizeText]]);
}

function kp_reference_image_data_uri($config, $state) {
    $fileId = (int)($state['recent_client_image']['file_id'] ?? 0);
    if ($fileId <= 0) return '';
    $download = bitrix_call($config, 'imbot.v2.File.download', [
        'botId' => (int)$config['bot_id'],
        'botToken' => $config['bot_token'],
        'fileId' => $fileId,
    ]);
    $url = (string)($download['result']['downloadUrl'] ?? '');
    if ($url === '') return '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_MAXREDIRS => 3,
    ]);
    $binary = curl_exec($ch);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($binary) || $binary === '' || $status < 200 || $status >= 300) return '';
    if (!preg_match('#^image/(?:jpeg|png|webp)#i', $contentType)) {
        $contentType = 'image/jpeg';
    }
    return 'data:' . strtolower(strtok($contentType, ';')) . ';base64,' . base64_encode($binary);
}

function resolve_chat_files($config, $conversationId, $files) {
    if (!preg_match('/^chat(\d+)$/', (string)$conversationId, $match)) return $files;
    $historyResult = bitrix_call($config, 'imopenlines.session.history.get', ['CHAT_ID' => (int)$match[1]]);
    $historyFiles = (array)($historyResult['result']['files'] ?? []);
    $resolved = [];
    foreach ((array)$files as $file) {
        $fileId = (int)($file['id'] ?? 0);
        $full = $historyFiles[(string)$fileId] ?? $historyFiles[$fileId] ?? [];
        $resolved[] = array_merge((array)$file, (array)$full);
    }
    return $resolved;
}

function audio_file_detected($file) {
    $type = mb_strtolower((string)($file['type'] ?? ''), 'UTF-8');
    $extension = mb_strtolower((string)($file['extension'] ?? pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)), 'UTF-8');
    return $type === 'audio' || in_array($extension, ['mp3', 'ogg', 'opus', 'wav', 'm4a', 'aac'], true);
}

function bitrix_file_binary($config, $fileId) {
    $download = bitrix_call($config, 'imbot.v2.File.download', [
        'botId' => (int)$config['bot_id'],
        'botToken' => $config['bot_token'],
        'fileId' => (int)$fileId,
    ]);
    $url = (string)($download['result']['downloadUrl'] ?? '');
    if ($url === '') return ['ok' => false, 'error' => $download['error'] ?? 'file_download_url_missing'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_MAXREDIRS => 3,
    ]);
    $binary = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if (!is_string($binary) || $binary === '' || $status < 200 || $status >= 300) {
        return ['ok' => false, 'error' => $error !== '' ? $error : 'file_download_failed_' . $status];
    }
    return ['ok' => true, 'binary' => $binary];
}

function speechkit_headers($config) {
    $key = trim((string)($config['llm_api_key'] ?? ''));
    $headers = ['Content-Type: application/json'];
    $headers[] = 'Authorization: ' . (strpos($key, 't1.') === 0 ? 'Bearer ' : 'Api-Key ') . $key;
    if (preg_match('#^gpt://([^/]+)/#', (string)($config['llm_model'] ?? ''), $match)) {
        $headers[] = 'x-folder-id: ' . $match[1];
    }
    return $headers;
}

function speechkit_transcribe($config, $binary, $extension) {
    if (empty($config['llm_api_key'])) return ['ok' => false, 'error' => 'speechkit_key_missing'];
    $extension = mb_strtolower((string)$extension, 'UTF-8');
    $container = in_array($extension, ['ogg', 'opus'], true) ? 'OGG_OPUS' :
        ($extension === 'wav' ? 'WAV' : 'MP3');
    $payload = [
        'content' => base64_encode($binary),
        'recognitionModel' => [
            'model' => 'general',
            'audioFormat' => ['containerAudio' => ['containerAudioType' => $container]],
            'textNormalization' => [
                'textNormalization' => 'TEXT_NORMALIZATION_ENABLED',
                'profanityFilter' => false,
                'literatureText' => false,
            ],
            'languageRestriction' => [
                'restrictionType' => 'WHITELIST',
                'languageCode' => ['ru-RU'],
            ],
        ],
    ];
    $ch = curl_init('https://stt.api.cloud.yandex.net/stt/v3/recognizeFileAsync');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => speechkit_headers($config),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    $operation = json_decode((string)$raw, true);
    $operationId = (string)($operation['id'] ?? '');
    if ($status < 200 || $status >= 300 || $operationId === '') {
        return ['ok' => false, 'error' => $operation['message'] ?? $operation['error']['message'] ?? $curlError ?: 'speech_submit_failed_' . $status];
    }
    $resultUrl = 'https://stt.api.cloud.yandex.net/stt/v3/getRecognition?operationId=' . rawurlencode($operationId);
    $lastResultRaw = '';
    $lastResultStatus = 0;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        if ($attempt > 0) usleep(1000000);
        $ch = curl_init($resultUrl);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => speechkit_headers($config),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $resultRaw = curl_exec($ch);
        $resultStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $lastResultRaw = (string)$resultRaw;
        $lastResultStatus = $resultStatus;
        if ($resultStatus === 404 || trim((string)$resultRaw) === '') continue;
        $texts = [];
        foreach (preg_split('/\r?\n/', trim((string)$resultRaw)) as $line) {
            $item = json_decode($line, true);
            if (!is_array($item)) continue;
            if (isset($item['result']) && is_array($item['result'])) $item = $item['result'];
            $refined = trim((string)($item['finalRefinement']['normalizedText']['alternatives'][0]['text'] ?? ''));
            $final = trim((string)($item['final']['alternatives'][0]['text'] ?? ''));
            $candidate = $refined !== '' ? $refined : $final;
            if ($candidate !== '') $texts[] = $candidate;
        }
        $uniqueTexts = [];
        $seenTexts = [];
        foreach ($texts as $recognizedText) {
            $normalizedText = mb_strtolower(trim((string)$recognizedText), 'UTF-8');
            if ($normalizedText === '' || isset($seenTexts[$normalizedText])) continue;
            $seenTexts[$normalizedText] = true;
            $uniqueTexts[] = trim((string)$recognizedText);
        }
        $texts = $uniqueTexts;
        if ($texts) return ['ok' => true, 'text' => trim(implode(' ', $texts)), 'operation_id' => $operationId];
    }
    return [
        'ok' => false,
        'error' => 'speech_recognition_timeout',
        'operation_id' => $operationId,
        'last_status' => $lastResultStatus,
        'last_response' => mb_substr($lastResultRaw, 0, 1200),
    ];
}

function kp_generate_and_deliver($config, $state, $dialogId) {
    $dealId = (int)($state['crm_deal_id'] ?? 0);
    if ($dealId <= 0 || $dialogId === '') {
        return ['ok' => false, 'error' => 'kp_required_context_missing'];
    }
    $pending = (array)($state['pending_kp_confirmation'] ?? []);
    $pendingItems = !empty($pending['items']) && is_array($pending['items'])
        ? array_slice($pending['items'], 0, 6)
        : [$pending];
    $multiItems = [];
    foreach ($pendingItems as $index => $pendingItem) {
        $size = kp_size_from_text($pendingItem['size'] ?? ($state['facts']['size'] ?? ''));
        if (!$size) return ['ok' => false, 'error' => 'kp_required_size_missing'];
        $input = array_merge($size, [
            'quantity' => (int)($pendingItem['quantity'] ?? 1),
            'group' => $pendingItem['group'] ?? 'mix',
            'plants' => $pendingItem['plants'] ?? 'none',
            'kit' => $pendingItem['kit'] ?? 'light',
            'frame' => $pendingItem['frame'] ?? 'mdf',
            'light' => $pendingItem['light'] ?? 'led_hidden',
            'remote' => !empty($pendingItem['remote']),
            'custom_frame_color' => !empty($pendingItem['custom_frame_color']),
        ]);
        $calculation = kp_calculate_three_variants($input);
        $widthLabel = rtrim(rtrim(number_format($size['width_cm'], 2, '.', ''), '0'), '.');
        $heightLabel = rtrim(rtrim(number_format($size['height_cm'], 2, '.', ''), '0'), '.');
        $label = trim((string)($pendingItem['label'] ?? ''));
        if ($label === '') $label = 'Вариант ' . ($index + 1) . ' · ' . $widthLabel . '×' . $heightLabel . ' см';
        $multiItems[] = [
            'calculation' => $calculation,
            'label' => $label,
            'size_label' => $widthLabel . '×' . $heightLabel . ' см',
        ];
    }
    try {
        $referenceImage = kp_reference_image_data_uri($config, $state);
        $pdf = count($multiItems) > 1
            ? kp_render_multi_pdf($multiItems, $referenceImage)
            : kp_render_pdf($multiItems[0]['calculation'], $multiItems[0]['label'], $referenceImage);
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => $error->getMessage()];
    }
    $sizeLabels = array_values(array_unique(array_column($multiItems, 'size_label')));
    $fileName = 'КП Панно ' . implode(', ', $sizeLabels) . ' Eco-Store.pdf';
    $encoded = base64_encode($pdf);
    $fileResult = bitrix_call($config, 'imbot.v2.File.upload', [
        'botId' => (int)$config['bot_id'],
        'botToken' => $config['bot_token'],
        'dialogId' => $dialogId,
        'fields' => [
            'name' => $fileName,
            'content' => $encoded,
            'message' => 'Подготовила предварительный расчёт в трёх вариантах) Посмотрите, какой вам ближе: Стандарт, Стандарт+ или Премиум?',
        ],
    ]);
    if (empty($fileResult['result'])) {
        return ['ok' => false, 'error' => $fileResult['error'] ?? 'chat_file_upload_failed'];
    }
    $standardTotal = (float)($multiItems[0]['calculation']['variants'][0]['total'] ?? 0);
    $dealUpdate = bitrix_call($config, 'crm.deal.update', [
        'id' => $dealId,
        'fields' => [
            'UF_CRM_1778228992' => [['fileData' => [$fileName, $encoded]]],
            'UF_CRM_1776769957086' => $standardTotal,
            'UF_CRM_1777548912' => implode('; ', $sizeLabels),
            'UF_CRM_1776773223826' => 1310,
            'UF_CRM_1777470579' => 1542,
            'UF_CRM_1777470285' => 1534,
            'UF_CRM_1777815326' => 1664,
        ],
    ]);
    return [
        'ok' => true,
        'deal_updated' => !empty($dealUpdate['result']),
        'message_id' => $fileResult['result']['messageId'] ?? 0,
        'file_id' => $fileResult['result']['file']['id'] ?? 0,
        'calculations' => array_column($multiItems, 'calculation'),
    ];
}

function has_later_message_from_sender($config, $conversationId, $messageId, $senderId) {
    if (!preg_match('/^chat(\d+)$/', (string)$conversationId, $match)) return false;
    if ((int)$messageId <= 0 || (int)$senderId <= 0) return false;
    $result = bitrix_call($config, 'imopenlines.session.history.get', ['CHAT_ID' => (int)$match[1]]);
    $messages = $result['result']['message'] ?? [];
    foreach ((array)$messages as $message) {
        if ((int)($message['id'] ?? 0) <= (int)$messageId) continue;
        if ((int)($message['senderid'] ?? 0) !== (int)$senderId) continue;
        if (trim((string)($message['text'] ?? '')) !== '') return true;
    }
    return false;
}

function chat_already_answered_after_message($config, $conversationId, $messageId, $clientSenderId) {
    if (!preg_match('/^chat(\d+)$/', (string)$conversationId, $match)) return false;
    if ((int)$messageId <= 0) return false;
    $result = bitrix_call($config, 'imopenlines.session.history.get', ['CHAT_ID' => (int)$match[1]]);
    $messages = $result['result']['message'] ?? [];
    foreach ((array)$messages as $message) {
        if ((int)($message['id'] ?? 0) <= (int)$messageId) continue;
        if (trim((string)($message['text'] ?? '')) === '') continue;
        $senderId = (int)($message['senderid'] ?? 0);
        if ($senderId === 0 || $senderId === (int)$clientSenderId) continue;
        return true;
    }
    return false;
}

function verify_message_in_chat($config, $conversationId, $messageId, $expectedText) {
    if (!preg_match('/^chat(\d+)$/', (string)$conversationId, $match)) {
        return ['ok' => false, 'reason' => 'invalid_chat_id'];
    }
    $expectedText = trim((string)$expectedText);
    for ($attempt = 0; $attempt < 3; $attempt++) {
        if ($attempt > 0) usleep(350000);
        $result = bitrix_call($config, 'imopenlines.session.history.get', ['CHAT_ID' => (int)$match[1]]);
        $messages = array_values((array)($result['result']['message'] ?? []));
        usort($messages, static function ($a, $b) {
            return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
        });
        foreach ($messages as $message) {
            if ((int)$messageId > 0 && (int)($message['id'] ?? 0) !== (int)$messageId) continue;
            $actual = trim((string)($message['text'] ?? ''));
            if (preg_match('/\?{4,}/u', $actual)) {
                return ['ok' => false, 'reason' => 'corrupted_question_marks', 'actual' => $actual];
            }
            $plainActual = trim(strip_tags(str_replace(['[URL]', '[/URL]'], '', $actual)));
            $plainExpected = trim(strip_tags(str_replace(['[URL]', '[/URL]'], '', $expectedText)));
            if ($plainExpected !== '' && $plainActual !== $plainExpected) {
                return ['ok' => false, 'reason' => 'text_mismatch', 'actual' => $actual];
            }
            return ['ok' => true, 'actual' => $actual, 'message_id' => (int)($message['id'] ?? 0)];
        }
    }
    return ['ok' => false, 'reason' => 'message_not_found'];
}

function notify_operator($config, $message, $tag) {
    $userId = (int)($config['alert_user_id'] ?? 2218);
    if ($userId <= 0) return false;
    $result = bitrix_call($config, 'im.notify.system.add', [
        'USER_ID' => $userId,
        'MESSAGE' => $message,
        'TAG' => $tag,
    ]);
    return isset($result['result']);
}

function media_request_detected($text, $reply = '') {
    $haystack = mb_strtolower(trim($text . ' ' . $reply), 'UTF-8');
    return (bool)preg_match(
        '/покаж|пришл|фото\s+пример|пример(?:ы|ов|ами|а)\b|вариант(?:ы|ов)\b|подберу.{0,30}пример/u',
        $haystack
    );
}

function media_folder_for_context($state, $text) {
    $facts = isset($state['facts']) && is_array($state['facts']) ? $state['facts'] : [];
    $haystack = mb_strtolower(
        trim($text . ' ' . (string)($facts['product'] ?? '') . ' ' . (string)($facts['request'] ?? '')),
        'UTF-8'
    );
    if (preg_match('/логотип|лого/u', $haystack)) return '/4. логотипы';
    if (preg_match('/круг|кругл/u', $haystack)) return '/3. круги';
    if (preg_match('/однотон|ягел/u', $haystack)) return '/2. Однотонный ягель';
    if (preg_match('/люстр/u', $haystack)) return '/5. Другие изделия/люстры';
    if (preg_match('/зеркал/u', $haystack)) return '/5. Другие изделия/зеркала';
    if (preg_match('/картин/u', $haystack)) return '/5. Другие изделия/интерьерные картины';
    if (preg_match('/искусственн|искуственн|фитостен/u', $haystack)) {
        return '/5. Другие изделия/панели из искуственных растений';
    }
    return '/1. Микс мха/Премиум';
}

function yandex_public_list($publicKey, $path, $limit = 100) {
    $url = 'https://cloud-api.yandex.net/v1/disk/public/resources?' . http_build_query([
        'public_key' => $publicKey,
        'path' => $path,
        'limit' => $limit,
        'fields' => '_embedded.items.name,_embedded.items.path,_embedded.items.type,' .
            '_embedded.items.media_type,_embedded.items.mime_type,_embedded.items.size,' .
            '_embedded.items.file,_embedded.items.preview',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $code < 200 || $code >= 300) {
        return ['items' => [], 'error' => $error !== '' ? $error : 'http_' . $code];
    }
    $data = json_decode($raw, true);
    $items = $data['_embedded']['items'] ?? [];
    return ['items' => is_array($items) ? $items : [], 'error' => ''];
}

function download_media_bytes($url, $maxBytes = 5500000) {
    if ($url === '') return ['bytes' => '', 'error' => 'empty_url'];
    if (strpos($url, 'downloader.disk.yandex.ru') !== false && preg_match('/([?&])size=[^&]*/', $url)) {
        $url = preg_replace('/([?&])size=[^&]*/', '$1size=XXL', $url, 1);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_HTTPHEADER => ['Accept: image/*'],
    ]);
    $bytes = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($bytes === false || $code < 200 || $code >= 300) {
        return ['bytes' => '', 'error' => $error !== '' ? $error : 'http_' . $code];
    }
    if (strlen($bytes) > $maxBytes) return ['bytes' => '', 'error' => 'too_large'];
    if ($type !== '' && stripos($type, 'image/') !== 0) return ['bytes' => '', 'error' => 'not_image'];
    return ['bytes' => $bytes, 'error' => ''];
}

function send_yandex_examples($config, $dialogId, $state, $text, $limit = 2) {
    $publicKey = (string)($config['yandex_media_public_key'] ?? 'https://disk.yandex.ru/d/ZLLcoSlnI_N9Qw');
    if ($publicKey === '') return ['sent' => 0, 'errors' => ['source_not_configured'], 'paths' => []];
    $folder = media_folder_for_context($state, $text);
    $listed = yandex_public_list($publicKey, $folder, 100);
    $items = [];
    foreach ($listed['items'] as $item) {
        if (($item['type'] ?? '') !== 'file' || ($item['media_type'] ?? '') !== 'image') continue;
        if (empty($item['preview']) && empty($item['file'])) continue;
        $items[] = $item;
    }
    if (!$items && $folder !== '/1. Микс мха/Премиум') {
        $folder = '/1. Микс мха/Премиум';
        $listed = yandex_public_list($publicKey, $folder, 100);
        foreach ($listed['items'] as $item) {
            if (($item['type'] ?? '') === 'file' && ($item['media_type'] ?? '') === 'image' &&
                (!empty($item['preview']) || !empty($item['file']))) $items[] = $item;
        }
    }
    $already = array_fill_keys((array)($state['sent_media_paths'] ?? []), true);
    $available = array_values(array_filter($items, function ($item) use ($already) {
        return empty($already[(string)($item['path'] ?? '')]);
    }));
    if (!$available) $available = $items;
    if ($available) {
        $offset = abs(crc32($dialogId . '|' . count((array)($state['sent_media_paths'] ?? [])))) % count($available);
        $available = array_merge(array_slice($available, $offset), array_slice($available, 0, $offset));
    }
    $sent = 0;
    $errors = [];
    $paths = [];
    foreach ($available as $item) {
        if ($sent >= $limit) break;
        $sourceUrl = (string)($item['preview'] ?? $item['file'] ?? '');
        $download = download_media_bytes($sourceUrl);
        if ($download['bytes'] === '') {
            $errors[] = (string)($item['name'] ?? 'image') . ':' . $download['error'];
            continue;
        }
        $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)($item['name'] ?? 'example.jpg'));
        if ($name === '' || strpos($name, '.') === false) $name = 'example_' . ($sent + 1) . '.jpg';
        $upload = bitrix_call($config, 'imbot.v2.File.upload', [
            'botId' => (int)$config['bot_id'],
            'botToken' => $config['bot_token'],
            'dialogId' => $dialogId,
            'fields' => ['name' => $name, 'content' => base64_encode($download['bytes'])],
        ]);
        if (!empty($upload['result']['messageId'])) {
            $sent++;
            $paths[] = (string)($item['path'] ?? '');
        } else {
            $errors[] = (string)($item['name'] ?? 'image') . ':' .
                (string)($upload['error'] ?? 'upload_failed');
        }
    }
    if ($listed['error'] !== '') $errors[] = 'list:' . $listed['error'];
    return ['sent' => $sent, 'errors' => $errors, 'paths' => $paths, 'folder' => $folder];
}

function bootstrap_state_from_openline($config, $conversationId, $currentMessageId, $state) {
    if (!empty($state['history']) || !preg_match('/^chat(\d+)$/', (string)$conversationId, $match)) return $state;
    $chatId = (int)$match[1];
    $result = bitrix_call($config, 'imopenlines.session.history.get', ['CHAT_ID' => $chatId]);
    $history = isset($result['result']) && is_array($result['result']) ? $result['result'] : [];
    if (!$history) return $state;

    $chat = [];
    if (isset($history['chat']) && is_array($history['chat'])) {
        if (isset($history['chat'][(string)$chatId])) $chat = $history['chat'][(string)$chatId];
        elseif ($history['chat']) $chat = reset($history['chat']);
    }
    $dealId = 0;
    $entityData = (string)($chat['entityData2'] ?? '');
    if (preg_match('/(?:^|\|)DEAL\|(\d+)(?:\||$)/', $entityData, $dealMatch)) {
        $dealId = (int)$dealMatch[1];
        $dealResult = bitrix_call($config, 'crm.deal.get', ['id' => $dealId]);
        $deal = isset($dealResult['result']) && is_array($dealResult['result']) ? $dealResult['result'] : [];
        $request = trim((string)($deal[$config['field_request']] ?? ''));
        if ($request !== '') {
            $state = extract_facts($request, $state);
            $state['facts']['request'] = $request;
            $state['crm_deal_id'] = $dealId;
        }
    }

    $users = isset($history['users']) && is_array($history['users']) ? $history['users'] : [];
    $messages = isset($history['message']) && is_array($history['message']) ? $history['message'] : [];
    if ($dealId > 0) {
        $activities = bitrix_call($config, 'crm.activity.list', [
            'order' => ['ID' => 'ASC'],
            'filter' => ['OWNER_TYPE_ID' => 2, 'OWNER_ID' => $dealId, 'PROVIDER_ID' => 'IMOPENLINES_SESSION'],
            'select' => ['ASSOCIATED_ENTITY_ID'],
        ]);
        $rows = isset($activities['result']) && is_array($activities['result']) ? $activities['result'] : [];
        foreach ($rows as $activity) {
            $sessionId = (int)($activity['ASSOCIATED_ENTITY_ID'] ?? 0);
            if ($sessionId <= 0) continue;
            $sessionResult = bitrix_call($config, 'imopenlines.session.history.get', ['SESSION_ID' => $sessionId]);
            $session = isset($sessionResult['result']) && is_array($sessionResult['result'])
                ? $sessionResult['result'] : [];
            foreach (($session['message'] ?? []) as $id => $message) $messages[(string)$id] = $message;
            foreach (($session['users'] ?? []) as $id => $user) $users[(string)$id] = $user;
        }
    }
    uasort($messages, function ($left, $right) {
        return (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0);
    });
    foreach ($messages as $message) {
        if (!is_array($message)) continue;
        $id = (string)($message['id'] ?? '');
        if ($id !== '' && $id === (string)$currentMessageId) continue;
        $text = trim(strip_tags((string)($message['text'] ?? $message['message'] ?? '')));
        if ($text === '' || preg_match('/^(Начат новый диалог|Обращение направлено|Обращение также направлено)/u', $text)) continue;
        if (preg_match('/\?{4,}/', $text)) continue;
        if (mb_strpos($text, 'Контактная информация сохранена') !== false ||
            mb_strpos($text, 'Сделка прикреплена') !== false ||
            mb_strpos($text, 'К чату присоединилась') !== false ||
            preg_match('/(?:начал|начала|завершил|завершила) работу с диалогом/u', $text)) continue;

        $senderId = (string)($message['senderid'] ?? '');
        $sender = isset($users[$senderId]) && is_array($users[$senderId]) ? $users[$senderId] : [];
        $isClient = !empty($sender['connector']) || !empty($sender['extranet']) ||
            in_array(mb_strtolower((string)($sender['type'] ?? ''), 'UTF-8'), ['external', 'extranet'], true);
        if (mb_strpos($text, 'Прочитано') !== false && mb_strpos($text, 'Отправки:') !== false) $isClient = false;
        if (mb_strpos($text, 'Исходящее сообщение') !== false && mb_strpos($text, 'Отправки:') !== false) $isClient = false;
        $role = $isClient ? 'user' : 'assistant';
        if ($role === 'user') $state = extract_facts($text, $state);
        $state = add_history($state, $role, $text);
    }
    $state['bootstrapped_from_openline'] = true;
    return $state;
}

function restore_recent_client_image($config, $conversationId, $state) {
    if (!empty($state['recent_client_image']['file_id']) ||
        !preg_match('/^chat(\d+)$/', (string)$conversationId, $match)) {
        return $state;
    }
    $result = bitrix_call($config, 'imopenlines.session.history.get', ['CHAT_ID' => (int)$match[1]]);
    $history = isset($result['result']) && is_array($result['result']) ? $result['result'] : [];
    $messages = isset($history['message']) && is_array($history['message']) ? $history['message'] : [];
    $users = isset($history['users']) && is_array($history['users']) ? $history['users'] : [];
    uasort($messages, function ($left, $right) {
        return (int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0);
    });
    foreach ($messages as $message) {
        if (!is_array($message)) continue;
        $senderId = (string)($message['senderid'] ?? '');
        $sender = isset($users[$senderId]) && is_array($users[$senderId]) ? $users[$senderId] : [];
        $isClient = !empty($sender['connector']) || !empty($sender['extranet']) ||
            in_array(mb_strtolower((string)($sender['type'] ?? ''), 'UTF-8'), ['external', 'extranet'], true);
        if (!$isClient) continue;
        $fileIds = (array)($message['params']['fileId'] ?? []);
        $fileId = (int)($fileIds[0] ?? 0);
        if ($fileId <= 0) continue;
        $state['recent_client_image'] = [
            'message_id' => (string)($message['id'] ?? ''),
            'file_id' => $fileId,
            'received_at' => gmdate('c'),
            'restored_from_history' => true,
        ];
        $state['facts']['selected_reference'] = true;
        break;
    }
    return $state;
}

function comment_field($comments, $labels) {
    foreach ($labels as $label) {
        if (preg_match('/^\s*' . preg_quote($label, '/') . '\s*:\s*(.+?)\s*$/imu', $comments, $match)) {
            return trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
    }
    return '';
}

function normalize_request_from_comments($comments) {
    $product = comment_field($comments, ['Формат', 'Изделие', 'Тип']);
    $room = comment_field($comments, ['Помещение', 'Где разместить']);
    $size = comment_field($comments, ['Размер (В×Ш)', 'Размер (ВхШ)', 'Размер']);
    $height = comment_field($comments, ['Высота']);
    $width = comment_field($comments, ['Ширина']);
    $view = comment_field($comments, ['Вид']);

    $productLow = mb_strtolower($product, 'UTF-8');
    if (mb_strpos($productLow, 'панно') !== false) $productText = 'панно';
    elseif (mb_strpos($productLow, 'линейн') !== false) $productText = 'линейная люстра';
    elseif ($product !== '' && mb_strpos($productLow, 'другое') === false) $productText = mb_strtolower($product, 'UTF-8');
    elseif ($view !== '') $productText = 'композиция «' . mb_strtolower($view, 'UTF-8') . '»';
    else $productText = '';

    $roomLow = mb_strtolower($room, 'UTF-8');
    $roomText = '';
    if (mb_strpos($roomLow, 'офис') !== false) $roomText = ' для офиса';
    elseif (preg_match('/дом|квартир/u', $roomLow)) $roomText = ' для дома';

    $sizeText = '';
    if ($height !== '' && $width !== '') $sizeText = ' ' . $height . ' на ' . $width . ' см';
    elseif ($size !== '') {
        $cleanSize = preg_replace('/\s*[×xх*]\s*/u', ' на ', $size);
        $sizeText = ' ' . trim($cleanSize);
        if (!preg_match('/(?:см|мм|\bм\b)/u', mb_strtolower($sizeText, 'UTF-8'))) $sizeText .= ' см';
    }
    $hasConcreteData = $sizeText !== '' || $roomText !== '' || $view !== '';
    if ($productText === '' || !$hasConcreteData) return '';
    return trim($productText . $roomText . $sizeText);
}

function get_openline_context($config, $dealId) {
    $activities = bitrix_call($config, 'crm.activity.list', [
        'order' => ['ID' => 'DESC'],
        'filter' => ['OWNER_TYPE_ID' => 2, 'OWNER_ID' => $dealId, 'PROVIDER_ID' => 'IMOPENLINES_SESSION'],
        'select' => ['ID', 'ASSOCIATED_ENTITY_ID', 'ORIGIN_ID'],
    ]);
    $rows = isset($activities['result']) && is_array($activities['result']) ? $activities['result'] : [];
    foreach ($rows as $activity) {
        $sessionId = (int)($activity['ASSOCIATED_ENTITY_ID'] ?? 0);
        if ($sessionId <= 0) continue;
        $historyResult = bitrix_call($config, 'imopenlines.session.history.get', ['SESSION_ID' => $sessionId]);
        $history = isset($historyResult['result']) && is_array($historyResult['result'])
            ? $historyResult['result'] : [];
        if (!$history) continue;
        $chatId = (int)($history['chatId'] ?? 0);
        $chat = [];
        if (isset($history['chat']) && is_array($history['chat'])) {
            if ($chatId > 0 && isset($history['chat'][(string)$chatId])) $chat = $history['chat'][(string)$chatId];
            elseif ($history['chat']) $chat = reset($history['chat']);
        }
        return [
            'session_id' => $sessionId,
            'chat_id' => $chatId,
            'entity_id' => (string)($chat['entityId'] ?? ''),
            'history' => $history,
        ];
    }
    return [];
}

function internal_dialog_match($config, $context) {
    $path = $config['data_dir'] . '/internal_dialogs.json';
    if (!is_file($path)) return '';
    $data = json_decode(file_get_contents($path), true);
    $blocked = isset($data['entity_ids']) && is_array($data['entity_ids']) ? $data['entity_ids'] : [];
    $entityId = (string)($context['entity_id'] ?? '');
    foreach ($blocked as $value) {
        $value = trim((string)$value);
        if ($value !== '' && hash_equals($value, $entityId)) return $value;
    }
    $authorId = (int)($context['author_id'] ?? 0);
    $blockedAuthors = isset($data['author_ids']) && is_array($data['author_ids']) ? $data['author_ids'] : [];
    foreach ($blockedAuthors as $value) {
        if ($authorId > 0 && (int)$value === $authorId) return 'author:' . $authorId;
    }
    return '';
}

function internal_context_by_conversation($config, $conversationId) {
    if (!preg_match('/^chat(\d+)$/', (string)$conversationId, $match)) return [];
    $chatId = (int)$match[1];
    $historyResult = bitrix_call($config, 'imopenlines.session.history.get', ['CHAT_ID' => $chatId]);
    $history = isset($historyResult['result']) && is_array($historyResult['result'])
        ? $historyResult['result'] : [];
    if (!$history) return [];
    $chat = [];
    if (isset($history['chat']) && is_array($history['chat'])) {
        if (isset($history['chat'][(string)$chatId])) $chat = $history['chat'][(string)$chatId];
        elseif ($history['chat']) $chat = reset($history['chat']);
    }
    $dealId = 0;
    $entityData = (string)($chat['entityData2'] ?? '');
    if (preg_match('/(?:^|\|)DEAL\|(\d+)(?:\||$)/', $entityData, $dealMatch)) {
        $dealId = (int)$dealMatch[1];
    }
    if ($dealId <= 0) {
        $entityData = (string)($chat['entityData1'] ?? '');
        if (preg_match('/(?:^|\|)DEAL\|(\d+)(?:\||$)/', $entityData, $dealMatch)) {
            $dealId = (int)$dealMatch[1];
        }
    }
    return [
        'chat_id' => $chatId,
        'session_id' => (int)($history['sessionId'] ?? 0),
        'entity_id' => (string)($chat['entityId'] ?? ''),
        'deal_id' => $dealId,
    ];
}

function live_test_dialog_allowed($config, $conversationId) {
    $path = $config['data_dir'] . '/live_test_dialogs.json';
    if (!is_file($path)) return false;
    $data = json_decode(file_get_contents($path), true);
    $dialogs = isset($data['conversation_ids']) && is_array($data['conversation_ids'])
        ? $data['conversation_ids'] : [];
    foreach ($dialogs as $value) {
        $value = trim((string)$value);
        if ($value !== '' && hash_equals($value, (string)$conversationId)) return true;
    }
    return false;
}

function normalize_request_from_dialog($config, $dealId) {
    $context = get_openline_context($config, $dealId);
    $history = isset($context['history']['message']) && is_array($context['history']['message'])
        ? $context['history']['message'] : [];
    if ($history) {
        $messages = [];
        foreach ($history as $message) {
            if (!is_array($message)) continue;
            $params = isset($message['params']) && is_array($message['params']) ? $message['params'] : [];
            if (empty($params['connectorMid'])) continue;
            $text = trim(strip_tags((string)($message['text'] ?? $message['message'] ?? '')));
            $text = preg_replace('/\[[^\]]+\]/u', '', $text);
            if ($text !== '') $messages[] = $text;
        }
        if (!$messages) return '';
        $candidate = trim(implode('. ', array_slice($messages, -5)));
        $low = mb_strtolower($candidate, 'UTF-8');
        if (preg_match('/тв[\s-]*зон/u', $low) && preg_match('/дизайн|визуал|эскиз/u', $low)) {
            return 'ТВ-зона, нужна разработка дизайна';
        }
        $hasProduct = preg_match('/панн|панел|мох|фитостен|люстр|логотип|лого|озелен|дерев/u', $low);
        $hasDetails = preg_match('/\d|размер|рам|подсвет|стекл|круг|овал|ниш|стен|офис|дом|квартир/u', $low);
        if (!$hasProduct || !$hasDetails) return '';
        $candidate = preg_replace('/\s+/u', ' ', $candidate);
        return mb_substr($candidate, 0, 300, 'UTF-8');
    }
    return '';
}

function extract_deal_id($value) {
    if (is_array($value)) {
        foreach (array_reverse($value) as $item) {
            $id = extract_deal_id($item);
            if ($id > 0) return $id;
        }
        return 0;
    }
    if (is_int($value) || is_float($value)) return max(0, (int)$value);
    $text = trim((string)$value);
    if ($text === '') return 0;
    if (ctype_digit($text)) return (int)$text;
    if (preg_match('/(?:DEAL[_:\-]?)?(\d+)\s*$/i', $text, $match)) return (int)$match[1];
    return 0;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$token = isset($_GET['token']) ? $_GET['token'] : '';
if (!hash_equals($config['event_token'], $token)) respond(403, ['ok' => false, 'error' => 'forbidden']);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'health') {
    $profile = bitrix_call($config, 'profile', []);
    $knowledgePath = $config['data_dir'] . '/knowledge.json';
    $knowledgeCount = 0;
    if (is_file($knowledgePath)) {
        $knowledgeData = json_decode(file_get_contents($knowledgePath), true);
        if (is_array($knowledgeData)) $knowledgeCount = count($knowledgeData);
    }
    respond(200, ['ok' => isset($profile['result']), 'mode' => $config['mode'], 'php' => PHP_VERSION,
        'bitrix_ok' => isset($profile['result']), 'knowledge_ok' => $knowledgeCount > 0,
        'knowledge_count' => $knowledgeCount]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'debug_recent') {
    $path = $config['data_dir'] . '/events.jsonl';
    $conversation = trim((string)($_GET['conversation_id'] ?? ''));
    $limit = max(1, min(20, (int)($_GET['limit'] ?? 8)));
    $records = [];
    if (is_file($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach (array_reverse($lines ?: []) as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) continue;
            if ($conversation !== '' && (string)($record['conversation_id'] ?? '') !== $conversation) continue;
            $records[] = $record;
            if (count($records) >= $limit) break;
        }
    }
    respond(200, ['ok' => true, 'records' => array_reverse($records)]);
}

// Выключатель для менеджера. Открывается ссылкой в браузере, отвечает обычной
// страницей, а не JSON, чтобы человеку было понятно текущее состояние.
if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
    in_array($action, ['stop_bot', 'start_bot', 'bot_status'], true)) {
    $flag = bot_disabled_flag_path($config);
    if ($action === 'stop_bot' && !is_file($flag)) {
        ensure_data_dir($config);
        file_put_contents($flag, gmdate('c'));
    }
    if ($action === 'start_bot' && is_file($flag)) unlink($flag);
    $disabled = is_file($flag);
    log_event($config, ['action' => $action, 'bot_disabled' => $disabled]);

    $title = $disabled ? 'Бот выключен' : 'Бот включён';
    $note = $disabled
        ? 'Клиентам он не отвечает. Все сообщения по-прежнему приходят в Битрикс, отвечайте вручную.'
        : 'Бот отвечает клиентам. Если вы сами напишете в диалог, в этом диалоге он замолчит автоматически.';
    $color = $disabled ? '#b3261e' : '#1b7f3b';
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(200);
    echo '<!doctype html><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>AI-продавец</title>'
        . '<div style="font:16px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;'
        . 'max-width:520px;margin:12vh auto;padding:0 20px;text-align:center">'
        . '<div style="font-size:26px;font-weight:600;color:' . $color . '">' . $title . '</div>'
        . '<p style="color:#444">' . $note . '</p>'
        . '<p style="margin-top:28px">'
        . '<a href="?action=' . ($disabled ? 'start_bot' : 'stop_bot') . '&token='
        . rawurlencode($token) . '" style="display:inline-block;padding:14px 26px;'
        . 'border-radius:10px;background:' . ($disabled ? '#1b7f3b' : '#b3261e')
        . ';color:#fff;text-decoration:none;font-weight:600">'
        . ($disabled ? 'Включить бота' : 'Выключить бота') . '</a></p>'
        . '</div>';
    exit;
}

// GET-обработчик для разблокировки диалога: ?action=resume&token=...&conversation_id=chatNNNN
// Отдаёт HTML-страницу с подтверждением, как stop_bot/start_bot
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'resume') {
    $conversationId = trim((string)($_GET['conversation_id'] ?? ''));
    if (!preg_match('/^chat\d+$/', $conversationId)) {
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Ошибка</title>'
            . '<div style="font:16px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;'
            . 'max-width:520px;margin:12vh auto;padding:0 20px;text-align:center">'
            . '<div style="font-size:26px;font-weight:600;color:#b3261e">Ошибка</div>'
            . '<p style="color:#444">Неверный ID диалога</p>'
            . '</div>';
        exit;
    }

    // Загрузить state, удалить флаги паузы
    $state = load_state($config, $conversationId);
    $wasPaused = !empty($state['paused_by_operator']);
    if ($wasPaused) {
        unset($state['paused_by_operator'], $state['paused_at'], $state['pause_reason']);
        save_state($config, $conversationId, $state);
    }
    log_event($config, ['action' => 'resume', 'conversation_id' => $conversationId, 'was_paused' => $wasPaused]);

    // Отдать HTML-страницу с подтверждением
    $title = 'Диалог разблокирован';
    $note = 'Бот сможет снова отвечать в этом диалоге.';
    $color = '#1b7f3b';
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(200);
    echo '<!doctype html><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>AI-продавец</title>'
        . '<div style="font:16px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;'
        . 'max-width:520px;margin:12vh auto;padding:0 20px;text-align:center">'
        . '<div style="font-size:26px;font-weight:600;color:' . $color . '">' . $title . '</div>'
        . '<p style="color:#444">' . $note . '</p>'
        . '<p style="margin-top:28px;color:#666;font-size:14px">Диалог: ' . htmlspecialchars($conversationId, ENT_QUOTES, 'utf-8') . '</p>'
        . '</div>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reset_state') {
    $body = request_payload();
    $conversationId = trim((string)($body['conversation_id'] ?? ''));
    if ($conversationId === '') respond(400, ['ok' => false, 'error' => 'conversation_id_required']);
    $path = state_path($config, $conversationId);
    $deleted = !is_file($path) || unlink($path);
    respond($deleted ? 200 : 500, [
        'ok' => $deleted,
        'conversation_id' => $conversationId,
        'deleted' => $deleted,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'pause_dialog') {
    $body = request_payload();
    $conversationId = trim((string)($body['conversation_id'] ?? ''));
    if (!preg_match('/^chat\d+$/', $conversationId)) {
        respond(400, ['ok' => false, 'error' => 'valid_conversation_id_required']);
    }
    $state = load_state($config, $conversationId);
    $state['paused_by_operator'] = true;
    $state['paused_at'] = gmdate('c');
    save_state($config, $conversationId, $state);
    respond(200, ['ok' => true, 'conversation_id' => $conversationId, 'paused' => true]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'resume_dialog') {
    $body = request_payload();
    $conversationId = trim((string)($body['conversation_id'] ?? ''));
    if (!preg_match('/^chat\d+$/', $conversationId)) {
        respond(400, ['ok' => false, 'error' => 'valid_conversation_id_required']);
    }
    $state = load_state($config, $conversationId);
    unset($state['paused_by_operator'], $state['paused_at'], $state['pause_reason']);
    save_state($config, $conversationId, $state);
    respond(200, ['ok' => true, 'conversation_id' => $conversationId, 'paused' => false]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'test_voice_file') {
    $body = request_payload();
    $conversationId = trim((string)($body['conversation_id'] ?? ''));
    $fileId = (int)($body['file_id'] ?? 0);
    if (!preg_match('/^chat\d+$/', $conversationId) || $fileId <= 0) {
        respond(400, ['ok' => false, 'error' => 'conversation_id_and_file_id_required']);
    }
    $files = resolve_chat_files($config, $conversationId, [['id' => $fileId]]);
    $file = $files[0] ?? [];
    if (!audio_file_detected($file)) respond(400, ['ok' => false, 'error' => 'audio_file_required']);
    $download = bitrix_file_binary($config, $fileId);
    if (empty($download['ok'])) respond(500, ['ok' => false, 'error' => $download['error'] ?? 'download_failed']);
    $extension = (string)($file['extension'] ?? pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $result = speechkit_transcribe($config, $download['binary'], $extension);
    respond(!empty($result['ok']) ? 200 : 500, $result);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'deliver_frame_comparison') {
    $body = request_payload();
    $conversationId = trim((string)($body['conversation_id'] ?? ''));
    if (!preg_match('/^chat\d+$/', $conversationId)) {
        respond(400, ['ok' => false, 'error' => 'valid_conversation_id_required']);
    }
    $state = load_state($config, $conversationId);
    $sizeText = trim((string)($state['facts']['size'] ?? ''));
    if ($sizeText === '') respond(400, ['ok' => false, 'error' => 'size_missing']);
    $state['pending_kp_confirmation'] = [
        'requested_at' => gmdate('c'),
        'items' => [
            [
                'label' => 'Вариант 1 · в раме',
                'size' => $sizeText,
                'kit' => 'frame',
                'frame' => 'mdf',
                'light' => 'none',
            ],
            [
                'label' => 'Вариант 2 · без рамы',
                'size' => $sizeText,
                'kit' => 'none',
                'frame' => 'mdf',
                'light' => 'none',
            ],
        ],
    ];
    $delivery = kp_generate_and_deliver($config, $state, $conversationId);
    if (!empty($delivery['ok'])) {
        unset($state['pending_kp_confirmation']);
        $state['kp_delivered_at'] = gmdate('c');
        $state['kp_delivery'] = [
            'deal_updated' => !empty($delivery['deal_updated']),
            'file_id' => (int)($delivery['file_id'] ?? 0),
            'message_id' => (int)($delivery['message_id'] ?? 0),
        ];
        save_state($config, $conversationId, $state);
    }
    respond(!empty($delivery['ok']) ? 200 : 500, [
        'ok' => !empty($delivery['ok']),
        'delivery' => $delivery,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'deliver_current_single') {
    $body = request_payload();
    $conversationId = trim((string)($body['conversation_id'] ?? ''));
    if (!preg_match('/^chat\d+$/', $conversationId)) {
        respond(400, ['ok' => false, 'error' => 'valid_conversation_id_required']);
    }
    $state = load_state($config, $conversationId);
    $sizeText = trim((string)($state['facts']['size'] ?? ''));
    if ($sizeText === '') respond(400, ['ok' => false, 'error' => 'size_missing']);
    $withoutLight = (($state['facts']['lighting'] ?? '') === 'без подсветки');
    $withoutFrame = (($state['facts']['frame'] ?? '') === 'без рамы');
    $state['pending_kp_confirmation'] = [
        'requested_at' => gmdate('c'),
        'size' => $sizeText,
        'kit' => $withoutFrame ? 'none' : ($withoutLight ? 'frame' : 'light'),
        'frame' => 'mdf',
        'light' => $withoutLight ? 'none' : 'led_hidden',
    ];
    $delivery = kp_generate_and_deliver($config, $state, $conversationId);
    if (!empty($delivery['ok'])) {
        unset($state['pending_kp_confirmation']);
        $state['kp_delivered_at'] = gmdate('c');
        $state['kp_delivery'] = [
            'deal_updated' => !empty($delivery['deal_updated']),
            'file_id' => (int)($delivery['file_id'] ?? 0),
            'message_id' => (int)($delivery['message_id'] ?? 0),
        ];
        save_state($config, $conversationId, $state);
    }
    respond(!empty($delivery['ok']) ? 200 : 500, [
        'ok' => !empty($delivery['ok']),
        'delivery' => $delivery,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'kp_preview') {
    $result = kp_calculate_three_variants([
        'width_cm' => $_GET['width_cm'] ?? 0,
        'height_cm' => $_GET['height_cm'] ?? 0,
        'quantity' => $_GET['quantity'] ?? 1,
        'group' => $_GET['group'] ?? 'mix',
        'plants' => $_GET['plants'] ?? 'none',
        'kit' => $_GET['kit'] ?? 'none',
        'frame' => $_GET['frame'] ?? 'mdf',
        'light' => $_GET['light'] ?? 'led_hidden',
        'remote' => bool_value($_GET['remote'] ?? false),
        'custom_frame_color' => bool_value($_GET['custom_frame_color'] ?? false),
    ]);
    respond(200, ['ok' => true, 'calculation' => $result]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'kp_pdf') {
    $calculation = kp_calculate_three_variants([
        'width_cm' => $_GET['width_cm'] ?? 0,
        'height_cm' => $_GET['height_cm'] ?? 0,
        'quantity' => $_GET['quantity'] ?? 1,
        'group' => $_GET['group'] ?? 'mix',
        'plants' => $_GET['plants'] ?? 'none',
        'kit' => $_GET['kit'] ?? 'none',
        'frame' => $_GET['frame'] ?? 'mdf',
        'light' => $_GET['light'] ?? 'led_hidden',
        'remote' => bool_value($_GET['remote'] ?? false),
        'custom_frame_color' => bool_value($_GET['custom_frame_color'] ?? false),
    ]);
    try {
        $imageDataUri = '';
        if (!empty($_GET['file_id'])) {
            $imageDataUri = kp_reference_image_data_uri($config, [
                'recent_client_image' => ['file_id' => (int)$_GET['file_id']],
            ]);
        }
        $pdf = kp_render_pdf($calculation, $_GET['client_name'] ?? '', $imageDataUri);
    } catch (Throwable $error) {
        respond(500, ['ok' => false, 'error' => $error->getMessage()]);
    }
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="eco-store-kp.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true) && $action === 'prepare_deal') {
    $payload = request_payload();
    $body = array_merge($_GET, $payload);
    $rawDealId = $body['deal_id'] ?? $body['DEAL_ID'] ?? $body['document_id']
        ?? $body['DOCUMENT_ID'] ?? ($body['data']['FIELDS']['ID'] ?? 0);
    $dealId = extract_deal_id($rawDealId);
    log_event($config, [
        'action' => 'prepare_deal_received',
        'method' => $_SERVER['REQUEST_METHOD'],
        'deal_id' => $dealId,
        'deal_id_shape' => is_array($rawDealId) ? 'array' : gettype($rawDealId),
        'query_keys' => array_values(array_diff(array_keys($_GET), ['token'])),
        'payload_keys' => array_keys($payload),
    ]);
    if ($dealId <= 0) {
        log_event($config, ['action' => 'prepare_deal', 'deal_id' => 0, 'updated' => false,
            'reason' => 'deal_id_required']);
        respond(400, ['ok' => false, 'error' => 'deal_id_required']);
    }
    $dealResult = bitrix_call($config, 'crm.deal.get', ['id' => $dealId]);
    $deal = isset($dealResult['result']) && is_array($dealResult['result']) ? $dealResult['result'] : [];
    if (!$deal) {
        log_event($config, ['action' => 'prepare_deal', 'deal_id' => $dealId, 'updated' => false,
            'reason' => 'deal_not_found', 'bitrix_error' => $dealResult['error'] ?? '']);
        respond(404, ['ok' => false, 'error' => 'deal_not_found']);
    }
    $openline = get_openline_context($config, $dealId);
    $internalMatch = internal_dialog_match($config, $openline);
    if ($internalMatch !== '') {
        $chatId = (int)($openline['chat_id'] ?? 0);
        $finish = $chatId > 0
            ? bitrix_call($config, 'imopenlines.operator.another.finish', ['CHAT_ID' => $chatId])
            : ['result' => false, 'error' => 'chat_id_missing'];
        $deleted = bitrix_call($config, 'crm.deal.delete', ['id' => $dealId]);
        $finishedOk = !empty($finish['result']);
        $deletedOk = !empty($deleted['result']);
        log_event($config, [
            'action' => 'internal_dialog_cleanup',
            'deal_id' => $dealId,
            'session_id' => (int)($openline['session_id'] ?? 0),
            'chat_id' => $chatId,
            'finished' => $finishedOk,
            'deleted' => $deletedOk,
            'finish_error' => $finishedOk ? '' : ($finish['error'] ?? 'finish_failed'),
            'delete_error' => $deletedOk ? '' : ($deleted['error'] ?? 'delete_failed'),
        ]);
        respond(($finishedOk && $deletedOk) ? 200 : 502, [
            'ok' => $finishedOk && $deletedOk,
            'deal_id' => $dealId,
            'internal_dialog' => true,
            'finished' => $finishedOk,
            'deleted' => $deletedOk,
        ]);
    }
    $field = $config['field_request'];
    $existing = trim((string)($deal[$field] ?? ''));
    if ($existing !== '') {
        log_event($config, ['action' => 'prepare_deal', 'deal_id' => $dealId, 'updated' => false,
            'reason' => 'already_filled']);
        respond(200, ['ok' => true, 'deal_id' => $dealId, 'updated' => false, 'reason' => 'already_filled']);
    }
    $request = normalize_request_from_comments((string)($deal['COMMENTS'] ?? ''));
    $requestSource = 'comments';
    if ($request === '') {
        $request = normalize_request_from_dialog($config, $dealId);
        $requestSource = 'openline';
    }
    if ($request === '') {
        log_event($config, ['action' => 'prepare_deal', 'deal_id' => $dealId, 'updated' => false,
            'reason' => 'request_not_parsed']);
        respond(422, ['ok' => false, 'deal_id' => $dealId, 'error' => 'request_not_parsed']);
    }
    $update = bitrix_call($config, 'crm.deal.update', ['id' => $dealId, 'fields' => [$field => $request]]);
    $updated = !empty($update['result']);
    log_event($config, ['action' => 'prepare_deal', 'deal_id' => $dealId, 'updated' => $updated,
        'request_source' => $requestSource, 'request' => $request,
        'error' => $updated ? '' : ($update['error'] ?? 'update_failed')]);
    respond($updated ? 200 : 502, ['ok' => $updated, 'deal_id' => $dealId, 'updated' => $updated,
        'request_source' => $requestSource, 'request' => $request,
        'error' => $updated ? '' : ($update['error'] ?? 'update_failed')]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'test' || $action === 'bitrix')) {
    $body = request_payload();
    $eventData = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
    $isV2 = isset($eventData['message']) || (isset($body['event']) && strpos((string)$body['event'], 'ONIMBOTV2') === 0);
    if ($isV2) {
        $params = $eventData;
        $conversationId = isset($eventData['chat']['dialogId']) ? (string)$eventData['chat']['dialogId'] :
            (isset($eventData['message']['chatId']) ? 'chat' . (string)$eventData['message']['chatId'] : 'test');
        $text = isset($eventData['message']['text']) ? (string)$eventData['message']['text'] : '';
        $files = isset($eventData['message']['files']) && is_array($eventData['message']['files']) ? $eventData['message']['files'] : [];
        $messageParams = isset($eventData['message']['params']) && is_array($eventData['message']['params'])
            ? $eventData['message']['params'] : [];
        if (!$files && !empty($messageParams['fileId'])) {
            foreach ((array)$messageParams['fileId'] as $fileId) {
                $files[] = ['id' => (string)$fileId, 'name' => 'client-image'];
            }
        }
    } else {
        $params = isset($eventData['PARAMS']) ? $eventData['PARAMS'] : (isset($body['PARAMS']) ? $body['PARAMS'] : $body);
        $conversationId = isset($params['DIALOG_ID']) ? (string)$params['DIALOG_ID'] : (isset($params['CHAT_ID']) ? (string)$params['CHAT_ID'] : 'test');
        $text = isset($params['MESSAGE']) ? (string)$params['MESSAGE'] : (isset($body['text']) ? (string)$body['text'] : '');
        $files = isset($params['FILES']) && is_array($params['FILES']) ? $params['FILES'] : [];
    }
    $eventName = isset($body['event']) ? (string)$body['event'] : '';
    $eventBotId = isset($eventData['bot']['id']) ? (int)$eventData['bot']['id'] : 0;
    $eventUserId = isset($eventData['user']['id']) ? (int)$eventData['user']['id'] : 0;
    $eventUserIsBot = bool_value($eventData['user']['bot'] ?? false);
    $eventUserIsConnector = bool_value($eventData['user']['connector'] ?? false);
    $eventUserIsExtranet = bool_value($eventData['user']['extranet'] ?? false);
    $eventUserType = mb_strtolower((string)($eventData['user']['type'] ?? ''), 'UTF-8');
    $externalAuthId = mb_strtolower((string)($eventData['user']['externalAuthId'] ?? ''), 'UTF-8');
    $messageIsSystem = bool_value($eventData['message']['isSystem'] ?? false);
    $messageId = (string)($eventData['message']['id'] ?? '');
    $dialogId = isset($eventData['chat']['dialogId']) ? (string)$eventData['chat']['dialogId'] : '';
    $isEmployee = $eventUserType === 'employee' || (!$eventUserIsConnector && !$eventUserIsExtranet && $externalAuthId === 'default');
    $isExternalClient = $eventUserIsConnector || $eventUserIsExtranet || in_array($eventUserType, ['external', 'extranet'], true);
    $state = load_state($config, $conversationId);
    if ($action === 'bitrix') {
        $internalContext = internal_context_by_conversation($config, $conversationId);
        $internalContext['author_id'] = $eventUserId;
        $internalMatch = internal_dialog_match($config, $internalContext);
        if ($internalMatch !== '') {
            $chatId = (int)($internalContext['chat_id'] ?? 0);
            $finish = $chatId > 0
                ? bitrix_call($config, 'imopenlines.operator.another.finish', ['CHAT_ID' => $chatId])
                : ['result' => false, 'error' => 'chat_id_missing'];
            if (empty($finish['result']) && $chatId > 0) {
                $finish = bitrix_call($config, 'imopenlines.bot.session.finish', [
                    'CHAT_ID' => $chatId,
                    'CLIENT_ID' => (string)($config['bot_token'] ?? ''),
                ]);
            }
            $dealId = (int)($internalContext['deal_id'] ?? 0);
            $deleted = $dealId > 0
                ? bitrix_call($config, 'crm.deal.delete', ['id' => $dealId])
                : ['result' => true];
            log_event($config, [
                'action' => 'internal_dialog_blocked_at_entry',
                'conversation_id' => $conversationId,
                'chat_id' => $chatId,
                'session_id' => (int)($internalContext['session_id'] ?? 0),
                'deal_id' => $dealId,
                'finished' => !empty($finish['result']),
                'deleted' => !empty($deleted['result']),
            ]);
            respond(200, ['ok' => true, 'internal_dialog' => true, 'ignored' => true,
                'finished' => !empty($finish['result']), 'deleted' => !empty($deleted['result'])]);
        }
        $state = bootstrap_state_from_openline($config, $conversationId, $messageId, $state);
        if ($eventName === 'ONIMBOTV2JOINCHAT') {
            unset($state['paused_by_operator'], $state['paused_at']);
            $state['bot_joined_at'] = gmdate('c');
            save_state($config, $conversationId, $state);
        }
    }
    $state = restore_recent_client_image($config, $conversationId, $state);

    // MAX may omit fileId from the webhook even though the file is already attached
    // to the message in Bitrix. Recover it from the actual chat history before
    // deciding that an empty-text event is empty.
    if ($action === 'bitrix' && trim($text) === '' && empty($files) &&
        $messageId !== '' && preg_match('/^chat(\d+)$/', $conversationId, $historyChatMatch)) {
        $historyResult = bitrix_call($config, 'imopenlines.session.history.get', [
            'CHAT_ID' => (int)$historyChatMatch[1],
        ]);
        $historyMessages = (array)($historyResult['result']['message'] ?? []);
        $historyMessage = $historyMessages[(string)$messageId] ?? null;
        if (!is_array($historyMessage)) {
            foreach ($historyMessages as $candidate) {
                if ((string)($candidate['id'] ?? '') === (string)$messageId) {
                    $historyMessage = $candidate;
                    break;
                }
            }
        }
        foreach ((array)(is_array($historyMessage) ? (($historyMessage['params']['fileId'] ?? [])) : []) as $fileId) {
            $files[] = ['id' => (string)$fileId, 'name' => 'client-file'];
        }
    }

    if ($action === 'bitrix') {
        $operatorTakeover = operator_takeover_message($text);
        $ignoreReason = '';
        if (bot_globally_disabled($config)) $ignoreReason = 'bot_disabled';
        elseif (!$isV2) $ignoreReason = 'not_v2';
        elseif ($eventName !== 'ONIMBOTV2MESSAGEADD') $ignoreReason = 'not_message_add';
        elseif ($messageIsSystem) $ignoreReason = 'system_message';
        elseif ($eventUserIsBot || ($eventBotId > 0 && $eventUserId === $eventBotId)) $ignoreReason = 'bot_message';
        elseif ($isEmployee) $ignoreReason = 'employee_message';
        elseif ($operatorTakeover) $ignoreReason = 'operator_takeover';
        elseif (!$isExternalClient) $ignoreReason = 'author_not_confirmed_external';
        elseif (trim($text) === '' && empty($files)) $ignoreReason = 'empty_message';

        // Как только в диалог вмешался живой человек, бот замолкает до конца сессии.
        // Это правило действует и в тестовых диалогах: раньше исключение для них
        // приводило к тому, что бот отвечал на реплики менеджера.
        if ($isEmployee || $operatorTakeover) {
            $state['paused_by_operator'] = true;
            $state['paused_at'] = gmdate('c');
            $state['pause_reason'] = $isEmployee ? 'employee_joined' : 'operator_replied_in_channel';
            save_state($config, $conversationId, $state);
        }
        if ($ignoreReason !== '') {
            log_event($config, ['action' => $action, 'conversation_id' => $conversationId,
                'event_name' => $eventName, 'message_id' => $messageId, 'author_id' => $eventUserId,
                'author_type' => $eventUserType, 'author_connector' => $eventUserIsConnector,
                'ignored' => true, 'ignore_reason' => $ignoreReason, 'sent' => false]);
            respond(200, ['ok' => true, 'mode' => $config['mode'], 'ignored' => true,
                'ignore_reason' => $ignoreReason, 'sent' => false]);
        }
        if (!empty($state['paused_by_operator'])) {
            log_event($config, ['action' => $action, 'conversation_id' => $conversationId,
                'event_name' => $eventName, 'message_id' => $messageId, 'author_id' => $eventUserId,
                'ignored' => true, 'ignore_reason' => 'paused_by_operator', 'sent' => false]);
            respond(200, ['ok' => true, 'mode' => $config['mode'], 'ignored' => true,
                'ignore_reason' => 'paused_by_operator', 'sent' => false]);
        }
        $key = $eventBotId . ':' . $conversationId . ':' . event_key($eventData);
        if (!claim_event($config, $key)) {
            log_event($config, ['action' => $action, 'conversation_id' => $conversationId,
                'event_name' => $eventName, 'message_id' => $messageId,
                'ignored' => true, 'ignore_reason' => 'duplicate_event', 'sent' => false]);
            respond(200, ['ok' => true, 'mode' => $config['mode'], 'ignored' => true,
                'ignore_reason' => 'duplicate_event', 'sent' => false]);
        }
        usleep(1800000);
        if (has_later_message_from_sender($config, $conversationId, $messageId, $eventUserId)) {
            log_event($config, ['action' => $action, 'conversation_id' => $conversationId,
                'event_name' => $eventName, 'message_id' => $messageId, 'author_id' => $eventUserId,
                'ignored' => true, 'ignore_reason' => 'superseded_by_later_client_message', 'sent' => false]);
            respond(200, ['ok' => true, 'mode' => $config['mode'], 'ignored' => true,
                'ignore_reason' => 'superseded_by_later_client_message', 'sent' => false]);
        }
    }

    if (!empty($files)) $files = resolve_chat_files($config, $conversationId, $files);
    if (trim($text) === '' && !empty($files) && audio_file_detected($files[0])) {
        $fileId = (int)($files[0]['id'] ?? 0);
        $extension = (string)($files[0]['extension'] ?? pathinfo((string)($files[0]['name'] ?? ''), PATHINFO_EXTENSION));
        $downloadedAudio = bitrix_file_binary($config, $fileId);
        $transcription = !empty($downloadedAudio['ok'])
            ? speechkit_transcribe($config, $downloadedAudio['binary'], $extension)
            : ['ok' => false, 'error' => $downloadedAudio['error'] ?? 'voice_download_failed'];
        if (empty($transcription['ok']) || trim((string)($transcription['text'] ?? '')) === '') {
            log_event($config, [
                'action' => $action,
                'conversation_id' => $conversationId,
                'event_name' => $eventName,
                'message_id' => $messageId,
                'author_id' => $eventUserId,
                'voice_received' => true,
                'voice_recognized' => false,
                'voice_error' => $transcription['error'] ?? 'empty_transcription',
                'sent' => false,
            ]);
            notify_operator($config, 'Не удалось распознать голосовое сообщение в диалоге ' . $conversationId, 'voice:' . $conversationId);
            respond(200, [
                'ok' => true,
                'mode' => $config['mode'],
                'voice_received' => true,
                'voice_recognized' => false,
                'needs_human' => true,
                'sent' => false,
            ]);
        }
        $text = trim((string)$transcription['text']);
        $state['last_voice'] = [
            'message_id' => $messageId,
            'file_id' => $fileId,
            'transcript' => $text,
            'recognized_at' => gmdate('c'),
        ];
        log_event($config, [
            'action' => 'voice_transcribed',
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'file_id' => $fileId,
            'transcript' => $text,
            'sent' => false,
        ]);
    }

    if (trim($text) === '' && !empty($files) && !audio_file_detected($files[0])) {
        $state['recent_client_image'] = [
            'message_id' => $messageId,
            'file_id' => (int)($files[0]['id'] ?? 0),
            'received_at' => gmdate('c'),
        ];
        $state['facts']['selected_reference'] = true;
        $state = add_history($state, 'user', '[Клиент прислал изображение]');
        save_state($config, $conversationId, $state);
        log_event($config, [
            'action' => $action,
            'conversation_id' => $conversationId,
            'event_name' => $eventName,
            'message_id' => $messageId,
            'author_id' => $eventUserId,
            'client_media_received' => true,
            'sent' => false,
        ]);
        respond(200, ['ok' => true, 'mode' => $config['mode'], 'client_media_received' => true, 'sent' => false]);
    }

    $state = extract_facts($text, $state);
    $state = add_history($state, 'user', $text);
    $hasObjectPhoto = false;
    $hasUnknownImage = false;
    foreach ($files as $file) {
        $name = mb_strtolower(isset($file['name']) ? $file['name'] : '', 'UTF-8');
        if (!empty($state['facts']['expects_object_photo']) || mb_strpos($text, 'фото стены') !== false) $hasObjectPhoto = true;
        elseif (preg_match('/\.(jpg|jpeg|png|webp)$/i', $name)) $hasUnknownImage = true;
    }
    $state['stage'] = decide_stage($state, $hasObjectPhoto);
    $fallback = fallback_reply($state, $hasObjectPhoto, $hasUnknownImage, $text);
    $knowledge = knowledge_search($config, $text);
    $writingShown = false;
    if ($action === 'bitrix' && $dialogId !== '') {
        $writingResult = bitrix_call($config, 'im.dialog.writing', ['DIALOG_ID' => $dialogId]);
        $writingShown = !empty($writingResult['result']);
    }
    $generated = ai_reply($config, $state, $text, $knowledge, $fallback);
    if (($generated['reason'] ?? '') === 'overload_step1') {
        $state['overload_asked_at'] = gmdate('c');
    }
    if (($generated['reason'] ?? '') === 'overload_step2') {
        $state['paused_by_operator'] = true;
        $state['pause_reason'] = 'overload_handoff';
        $urgency = mb_strtolower($text, 'UTF-8');
        if (preg_match('/срочно|завтра|сегодня|завтрак|обед|вечер|ночь|утро|завтр/u', $urgency)) {
            $urgency = 'срочно';
        } else {
            $urgency = 'не срочно';
        }
        notify_operator(
            $config,
            'Клиент указал срочность: ' . $urgency . '. Диалог ' . $conversationId . '. Последнее сообщение: ' . mb_substr($text, 0, 200, 'UTF-8'),
            'overload_handoff_' . $conversationId
        );
    }
    if (($generated['reason'] ?? '') === 'kp_multi_size_generate') {
        $withoutLight = (($state['facts']['lighting'] ?? '') === 'без подсветки');
        $withoutFrame = (($state['facts']['frame'] ?? '') === 'без рамы');
        $items = [];
        foreach ((array)($generated['sizes'] ?? []) as $index => $sizeText) {
            $items[] = [
                'label' => 'Вариант ' . ($index + 1) . ' · ' . $sizeText,
                'size' => $sizeText,
                'kit' => $withoutFrame ? 'none' : ($withoutLight ? 'frame' : 'light'),
                'frame' => 'mdf',
                'light' => $withoutLight ? 'none' : 'led_hidden',
            ];
        }
        $state['pending_kp_confirmation'] = [
            'requested_at' => gmdate('c'),
            'items' => $items,
        ];
    }
    if (($generated['reason'] ?? '') === 'kp_multi_generate') {
        $sizeText = (string)($state['facts']['size'] ?? '');
        $withoutLight = (($state['facts']['lighting'] ?? '') === 'без подсветки');
        $state['pending_kp_confirmation'] = [
            'requested_at' => gmdate('c'),
            'items' => [
                [
                    'label' => 'Вариант 1 · в раме',
                    'size' => $sizeText,
                    'kit' => $withoutLight ? 'frame' : 'light',
                    'frame' => 'mdf',
                    'light' => $withoutLight ? 'none' : 'led_hidden',
                ],
                [
                    'label' => 'Вариант 2 · без рамы',
                    'size' => $sizeText,
                    'kit' => 'none',
                    'frame' => 'mdf',
                    'light' => 'none',
                ],
            ],
        ];
    }
    if (($generated['reason'] ?? '') === 'selected_reference_confirm_configuration') {
        $withoutLight = (($state['facts']['lighting'] ?? '') === 'без подсветки');
        $withoutFrame = (($state['facts']['frame'] ?? '') === 'без рамы');
        $state['pending_kp_confirmation'] = [
            'requested_at' => gmdate('c'),
            'size' => (string)($state['facts']['size'] ?? ''),
            'kit' => $withoutFrame ? 'none' : ($withoutLight ? 'frame' : 'light'),
            'frame' => 'mdf',
            'light' => $withoutLight ? 'none' : 'led_hidden',
        ];
    }
    if (($generated['reason'] ?? '') === 'kp_single_generate') {
        $withoutLight = (($state['facts']['lighting'] ?? '') === 'без подсветки');
        $withoutFrame = (($state['facts']['frame'] ?? '') === 'без рамы');
        $state['pending_kp_confirmation'] = [
            'requested_at' => gmdate('c'),
            'size' => (string)($state['facts']['size'] ?? ''),
            'kit' => $withoutFrame ? 'none' : ($withoutLight ? 'frame' : 'light'),
            'frame' => 'mdf',
            'light' => $withoutLight ? 'none' : 'led_hidden',
        ];
    }
    $needsHuman = !empty($generated['needs_human']);
    $reply = sanitize_reply(
        $generated['text'] ?? 'Сейчас уточню этот момент и вернусь к вам)',
        $state,
        $fallback
    );

    // СТРАХОВКА: Самопроверка бота на странные/противоречивые ответы
    // Срабатывает если ответ выглядит неправильно или содержит ошибки
    $replyLow = mb_strtolower($reply, 'UTF-8');
    $shouldTriggerSelfCheck = false;
    $selfCheckReason = '';

    // Проверка 1: бот спрашивает дважды один и тот же параметр
    if (!empty($state['facts']['size']) && preg_match('/какой.{0,30}размер|размер.{0,30}[?]/u', $replyLow)) {
        $shouldTriggerSelfCheck = true;
        $selfCheckReason = 'repeated_size_question';
    }
    if (!empty($state['facts']['frame']) && preg_match('/рам[ау]?.{0,30}[?]|нужн[ау].{0,30}рам/u', $replyLow)) {
        $shouldTriggerSelfCheck = true;
        $selfCheckReason = 'repeated_frame_question';
    }
    if (!empty($state['facts']['lighting']) && preg_match('/подсвет.{0,30}[?]|нужн[ау].{0,30}подсвет/u', $replyLow)) {
        $shouldTriggerSelfCheck = true;
        $selfCheckReason = 'repeated_lighting_question';
    }

    // Проверка 2: ответ очень странный - слишком короткий после КП, или порвана логика
    if (empty($reply) || strlen(trim($reply)) < 5) {
        $shouldTriggerSelfCheck = true;
        $selfCheckReason = 'reply_too_short_or_empty';
    }

    // Если сработала страховка - заменить ответ и выставить паузу
    if ($shouldTriggerSelfCheck && ($generated['source'] ?? '') === 'llm_text') {
        $reply = 'Извините, я пока стажер, учусь, иногда ошибаюсь) Дайте мне уточнить этот момент, скоро вернусь.';
        $state['paused_by_operator'] = true;
        $state['pause_reason'] = 'self_check_triggered_' . $selfCheckReason;
        $needsHuman = true;
        $generated['source'] = 'self_check_guard';
        // Уведомление Алине о срабатывании страховки
        notify_operator(
            $config,
            'Сработала самопроверка бота в диалоге ' . $conversationId . '. Причина: ' . $selfCheckReason . '. Оригинальный ответ: ' . mb_substr($generated['text'] ?? '', 0, 150, 'UTF-8'),
            'self_check_' . $conversationId . '_' . time()
        );
    }

    if (!empty($state['kp_delivered_at'])) {
        $reply = preg_replace(
            '/\s*(?:Кстати,\s*)?(?:вы\s+не\s+ответили\s*,?\s*)?[Кк]\s+какой\s+дате[^?]*\?/u',
            '',
            $reply
        );
        $reply = trim((string)$reply);
    }
    if (!empty($state['facts']['object_photo_unavailable']) &&
        preg_match('/(?:пришл|можете\s+прислать).{0,30}фото\s+стен/u', mb_strtolower($reply, 'UTF-8'))) {
        $reply = 'Фото стены не нужно) Примеры работ можно посмотреть здесь: https://disk.yandex.ru/d/ZLLcoSlnI_N9Qw';
        $generated['source'] = 'guard_object_photo_unavailable';
    }
    if (strpos((string)($generated['source'] ?? ''), 'llm') === 0 &&
        preg_match('/\d[\d\s]*(?:руб|₽)/iu', $reply)) {
        $reply = structured_safe_reply($state, $fallback);
        $generated['source'] = 'structured_after_unverified_price';
    }
    if (reply_is_incomplete($reply)) {
        $reply = structured_safe_reply($state, $fallback);
        $generated['source'] = 'structured_after_incomplete_llm';
    }
    $repeatedReplyBlocked = reply_repeats_history($reply, $state);
    if ($repeatedReplyBlocked) {
        $reply = '';
        $needsHuman = true;
    }
    $sent = false;
    $sendError = '';
    $fallbackTransport = false;
    $transferredToOperator = false;
    $markedRead = false;
    $alreadyAnswered = false;
    $kpDelivery = [];
    $mediaResult = ['sent' => 0, 'errors' => [], 'paths' => []];
    $liveTest = live_test_dialog_allowed($config, $conversationId);
    $canSend = trim($reply) !== '' && $action === 'bitrix' && !bot_globally_disabled($config) &&
        ($config['mode'] === 'auto' || $liveTest) && $isV2 &&
        $eventName === 'ONIMBOTV2MESSAGEADD' && trim($text) !== '' && $isExternalClient &&
        !$eventUserIsBot && !$isEmployee &&
        $dialogId !== '' && !empty($config['bot_id']) && !empty($config['bot_token']);
    if ($canSend) {
        $alreadyAnswered = chat_already_answered_after_message(
            $config,
            $conversationId,
            $messageId,
            $eventUserId
        );
        if ($alreadyAnswered) $canSend = false;
    }
    if ($canSend) {
        $readResult = bitrix_call($config, 'imbot.v2.Chat.Message.read', [
            'botId' => (int)$config['bot_id'],
            'botToken' => $config['bot_token'],
            'dialogId' => $dialogId,
            'messageId' => (int)$messageId,
        ]);
        $markedRead = isset($readResult['result']);
        if (in_array(($generated['reason'] ?? ''), ['kp_confirmed_generate', 'kp_multi_generate', 'kp_multi_size_generate', 'kp_single_generate'], true)) {
            $kpDelivery = kp_generate_and_deliver($config, $state, $dialogId);
            $sent = !empty($kpDelivery['ok']);
            if ($sent) {
                unset($state['pending_kp_confirmation']);
                $state['kp_delivered_at'] = gmdate('c');
                $state['kp_delivery'] = [
                    'deal_updated' => !empty($kpDelivery['deal_updated']),
                    'file_id' => (int)($kpDelivery['file_id'] ?? 0),
                    'message_id' => (int)($kpDelivery['message_id'] ?? 0),
                ];
            } else {
                $sendError = (string)($kpDelivery['error'] ?? 'kp_delivery_failed');
            }
        } else {
            $sendResult = bitrix_call($config, 'imbot.v2.Chat.Message.send', [
                'botId' => (int)$config['bot_id'],
                'botToken' => $config['bot_token'],
                'dialogId' => $dialogId,
                'fields' => ['message' => $reply],
            ]);
            $sent = isset($sendResult['result']);
            $sentMessageId = is_array($sendResult['result'] ?? null)
                ? (int)($sendResult['result']['messageId'] ?? $sendResult['result']['id'] ?? 0)
                : (int)($sendResult['result'] ?? 0);
            if (!$sent) $sendError = isset($sendResult['error']) ? (string)$sendResult['error'] : 'send_failed';
            if (!$sent && preg_match('/^chat(\d+)$/', $dialogId, $chatMatch)) {
                $transportResult = bitrix_call($config, 'imopenlines.bot.session.message.send', [
                    'CHAT_ID' => (int)$chatMatch[1],
                    'NAME' => 'DEFAULT',
                    'MESSAGE' => $reply,
                ]);
                $sent = !empty($transportResult['result']);
                $fallbackTransport = $sent;
                if (!$sent && $sendError === '') {
                    $sendError = isset($transportResult['error']) ? (string)$transportResult['error'] : 'fallback_send_failed';
                }
            }
            if ($sent && !$fallbackTransport) {
                $verification = verify_message_in_chat($config, $conversationId, $sentMessageId, $reply);
                if (empty($verification['ok'])) {
                    $sent = false;
                    $sendError = 'post_send_verification_failed:' . (string)($verification['reason'] ?? 'unknown');
                    $state['paused_by_operator'] = true;
                    $state['pause_reason'] = $sendError;
                    notify_operator(
                        $config,
                        'AI-продавец остановлен: отправленное сообщение не прошло проверку в диалоге ' . $dialogId . '.',
                        'ai_seller_verify_error_' . $dialogId
                    );
                }
            }
        }
        if ($sent && !empty($config['send_media_files']) && media_request_detected($text)) {
            $mediaResult = send_yandex_examples($config, $dialogId, $state, $text, 2);
            if (!empty($mediaResult['paths'])) {
                $state['sent_media_paths'] = array_values(array_unique(array_merge(
                    (array)($state['sent_media_paths'] ?? []),
                    $mediaResult['paths']
                )));
                if (count($state['sent_media_paths']) > 30) {
                    $state['sent_media_paths'] = array_slice($state['sent_media_paths'], -30);
                }
            }
        }
        $autoTransfer = !empty($config['auto_transfer_to_operator']);
        if ($sent && $needsHuman && $autoTransfer && preg_match('/^chat(\d+)$/', $dialogId, $chatMatch)) {
            $transferResult = bitrix_call($config, 'imopenlines.bot.session.operator', [
                'CHAT_ID' => (int)$chatMatch[1],
            ]);
            $transferredToOperator = !empty($transferResult['result']);
        }
        if ($sent && $needsHuman && !$autoTransfer) {
            notify_operator(
                $config,
                'AI-продавцу нужен ваш взгляд. Диалог ' . $dialogId . ': ' . mb_substr($text, 0, 180, 'UTF-8'),
                'ai_seller_attention_' . $dialogId
            );
        }
        if (!$sent) {
            notify_operator(
                $config,
                'AI-продавец не смог отправить ответ в ' . $dialogId . '. Ошибка: ' .
                    ($sendError !== '' ? $sendError : 'неизвестная ошибка'),
                'ai_seller_send_error_' . $dialogId
            );
        }
    }
    if ($sent) $state = add_history($state, 'assistant', $reply);
    save_state($config, $conversationId, $state);
    log_event($config, ['action' => $action, 'conversation_id' => $conversationId, 'text' => $text,
        'event_name' => $eventName, 'message_id' => $messageId, 'author_id' => $eventUserId,
        'author_type' => $eventUserType, 'author_connector' => $eventUserIsConnector,
        'state' => $state, 'suggested_reply' => $reply, 'reply_source' => $generated['source'],
        'knowledge_hits' => count($knowledge), 'shadow' => $config['mode'] === 'shadow',
        'live_test' => $liveTest, 'needs_human' => $needsHuman,
        'writing_shown' => $writingShown, 'marked_read' => $markedRead,
        'already_answered_before_send' => $alreadyAnswered,
        'repeated_reply_blocked' => $repeatedReplyBlocked,
        'media_sent' => $mediaResult['sent'], 'media_errors' => $mediaResult['errors'],
        'media_folder' => $mediaResult['folder'] ?? '',
        'kp_delivery' => $kpDelivery,
        'fallback_transport' => $fallbackTransport, 'transferred_to_operator' => $transferredToOperator,
        'sent' => $sent, 'send_error' => $sendError]);
    respond(200, ['ok' => true, 'mode' => $config['mode'], 'state' => $state,
        'suggested_reply' => $reply, 'reply_source' => $generated['source'],
        'knowledge_hits' => count($knowledge), 'sent' => $sent, 'send_error' => $sendError,
        'already_answered_before_send' => $alreadyAnswered,
        'media_sent' => $mediaResult['sent'], 'media_errors' => $mediaResult['errors']]);
}

respond(404, ['ok' => false, 'error' => 'not_found']);
