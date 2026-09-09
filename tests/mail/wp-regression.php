<?php
/**
 * WordPress/WooCommerce regression checks for the email adapter.
 * Run through the Playground blueprint in README.md; all HTTP is intercepted.
 */
require_once '/wordpress/wp-load.php';
WC()->mailer();

/** Fail the blueprint immediately with the failed behavioral assertion. */
function universally_qa_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** A real WooCommerce customer email with a selectable rendering mode. */
class Universally_QA_Email extends WC_Email
{
    /** Rendering mode exercised by the adapter. */
    public string $qaType = 'plain';

    /** Set a known customer-email identity without sending any real mail. */
    public function __construct()
    {
        $this->id = 'customer_processing_order';
        $this->customer_email = true;
        parent::__construct();
    }

    /** Select plain or HTML formatting for each regression case. */
    public function get_email_type()
    {
        return $this->qaType;
    }
}

update_option('universally_api_key', str_repeat('a', 64));
update_option('universally_settings', ['translate_emails' => true]);
set_transient('universally_all_languages', [['variant' => 'de-de', 'urlPrefix' => 'de', 'isSource' => false, 'isDisabled' => false]], 3600);

$requests = [];
$mode = 'success';
/** Intercept the real WP HTTP boundary, preserving the actual client and adapter. */
$intercept = static function ($preempt, array $args, string $url) use (&$requests, &$mode) {
    $raw = $args['body'] ?? '';
    if (($args['headers']['Content-Encoding'] ?? '') === 'gzip') {
        $raw = gzdecode($raw);
    }
    $body = json_decode($raw, true);
    $requests[] = ['url' => $url, 'body' => $body];
    if ($mode === 'failure') {
        return new WP_Error('controlled_failure', 'Controlled translator failure');
    }
    if ($mode === 'malformed-data') {
        return ['response' => ['code' => 200], 'body' => wp_json_encode(['success' => true, 'data' => 'invalid']), 'headers' => []];
    }
    $data = ['metadata' => ['limitReached' => $mode === 'quota']];
    if (isset($body['strings'])) {
        $data['translations'] = [];
        foreach ($body['strings'] as $source) {
            $translated = 'DE ' . $source;
            if ($mode === 'drop-token') {
                $translated = preg_replace('/\{universally_email_url_[0-9]+\}/', '', $translated);
            }
            if ($mode === 'duplicate-token') {
                $translated .= '{universally_email_url_0}';
            }
            if ($mode === 'wrapped-token') {
                $translated = preg_replace('/(\{universally_email_url_[0-9]+\})/', '{$1}', $translated);
            }
            $data['translations'][$source] = $translated;
        }
    } else {
        $data['translatedHtml'] = $mode === 'malformed-html' ? ['invalid'] : '<p>DE Order #19</p>';
        $data['metadata']['stringsTranslated'] = 1;
    }
    return ['response' => ['code' => 200], 'body' => wp_json_encode(['success' => true, 'data' => $data]), 'headers' => []];
};
add_filter('pre_http_request', $intercept, 10, 3);

$order = new WC_Order();
$order->update_meta_data(Universally\Mail\OrderLanguage::META_KEY, 'de-de');
$email = new Universally_QA_Email();
$email->object = $order;
$args = ['qa@example.test', 'Order #19', "Please pay here: https://shop.example/order-pay/19/?key=wc_order_secret\n\nThank you", [], []];
$passed = [];

foreach (['customer_reset_password', 'customer_new_account'] as $id) {
    $requests = [];
    $email->id = $id;
    $result = apply_filters('woocommerce_mail_callback_params', $args, $email);
    universally_qa_assert($result === $args && $requests === [], 'Credential email must be rejected even with a WC_Order object');
    $passed[] = $id . ' denied';
}
$email->id = 'customer_processing_order';
$requests = [];
$result = apply_filters('woocommerce_mail_callback_params', $args, $email);
universally_qa_assert($result[1] === 'DE Order #19', 'Subject should be translated');
universally_qa_assert(strpos($result[2], 'https://shop.example/order-pay/19/?key=wc_order_secret') !== false, 'Original payment URL must be restored');
universally_qa_assert(strpos(wp_json_encode($requests), 'wc_order_secret') === false, 'Credential URL must not reach the translator');
universally_qa_assert(strpos($result[2], '{universally_email_url_') === false, 'No local URL token may reach the email');
foreach ($requests as $request) {
    universally_qa_assert(($request['body']['tokenizeNumbers'] ?? false) === true, 'Both strings calls opt into numeric tokenization');
}
$passed[] = 'plain body and subject, protected URL and numeric flag';

$mode = 'drop-token';
$result = apply_filters('woocommerce_mail_callback_params', $args, $email);
universally_qa_assert($result === $args, 'Missing link token must keep original email');
$passed[] = 'missing token fallback';
$mode = 'duplicate-token';
$result = apply_filters('woocommerce_mail_callback_params', $args, $email);
universally_qa_assert($result === $args, 'Duplicate link token must keep original email');
$passed[] = 'duplicate token fallback';
$mode = 'wrapped-token';
$result = apply_filters('woocommerce_mail_callback_params', $args, $email);
universally_qa_assert($result === $args, 'Wrapped link token must preserve original email without braces around its URL');
$passed[] = 'wrapped token fallback';
$mode = 'success';
$client = new Universally\Mail\EmailTranslator();
$first = 'Pay https://shop.example/pay/?key=first';
$second = 'Pay https://shop.example/pay/?key=second';
$result = $client->translateStrings([$first, $second], 'de-de');
universally_qa_assert($result[$first] === 'DE ' . $first && $result[$second] === 'DE ' . $second, 'Each occurrence restores its own URL');
$passed[] = 'shared template distinct live URLs';
$requests = [];
$result = $client->translateStrings(['Literal {universally_email_url_0}'], 'de-de');
universally_qa_assert($result === null && $requests === [], 'Literal marker must fail safely before requesting translation');
$passed[] = 'literal marker collision';

foreach (['failure', 'quota', 'malformed-data'] as $failureMode) {
    $mode = $failureMode;
    $result = apply_filters('woocommerce_mail_callback_params', $args, $email);
    universally_qa_assert($result === $args, 'Failed/limited request must preserve original mail');
    $passed[] = $failureMode . ' preserves original';
}
$mode = 'success';
$email->qaType = 'html';
$requests = [];
$htmlArgs = $args;
$htmlArgs[2] = '<p>Your order #19</p>';
$result = apply_filters('woocommerce_mail_callback_params', $htmlArgs, $email);
universally_qa_assert($result[2] === '<p>DE Order #19</p>', 'HTML body should be translated');
universally_qa_assert(count($requests) === 2 && $requests[0]['body']['tokenizeNumbers'] === true && $requests[1]['body']['tokenizeNumbers'] === true, 'HTML and subject both opt in');
universally_qa_assert(strpos($requests[0]['body']['sourceUrl'], '/__email__/wc/customer_processing_order/') !== false, 'HTML source is a full reserved same-site URL');
$passed[] = 'HTML body and numeric flag';
$mode = 'malformed-html';
$result = apply_filters('woocommerce_mail_callback_params', $htmlArgs, $email);
universally_qa_assert($result === $htmlArgs, 'Malformed HTML response must preserve original email');
$passed[] = 'malformed HTML preserves original';
$mode = 'success';

$_COOKIE['universally_lang'] = 'de';
$classicOrder = new WC_Order();
do_action('woocommerce_checkout_create_order', $classicOrder);
universally_qa_assert($classicOrder->get_meta(Universally\Mail\OrderLanguage::META_KEY) === 'de-de', 'Classic checkout must capture locale from its cookie');
$classicOrder->save();
$storedClassic = new WC_Order($classicOrder->get_id());
universally_qa_assert($storedClassic->get_meta(Universally\Mail\OrderLanguage::META_KEY) === 'de-de', 'Classic checkout locale must persist with the order');
$passed[] = 'classic checkout persists locale';
$blocksOrder = new WC_Order();
$blocksOrder->save();
do_action('woocommerce_store_api_checkout_order_processed', $blocksOrder);
$storedBlocks = new WC_Order($blocksOrder->get_id());
universally_qa_assert($storedBlocks->get_meta(Universally\Mail\OrderLanguage::META_KEY) === 'de-de', 'Store API capture must save locale on an existing order');
$passed[] = 'Store API checkout persists locale';
unset($_COOKIE['universally_lang']);

update_option('universally_settings', ['translate_emails' => false]);
$requests = [];
$result = apply_filters('woocommerce_mail_callback_params', $args, $email);
universally_qa_assert($result === $args && $requests === [], 'Disabled setting must make no translation request');
$passed[] = 'disabled setting';

remove_filter('pre_http_request', $intercept, 10);
file_put_contents('/wordpress/qa-results/mail-result.json', wp_json_encode(['wp' => get_bloginfo('version'), 'php' => PHP_VERSION, 'woocommerce' => WC_VERSION, 'passed' => $passed], JSON_PRETTY_PRINT));
echo count($passed) . " email regression scenarios passed\n";
