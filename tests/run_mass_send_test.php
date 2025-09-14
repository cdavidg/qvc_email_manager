<?php
// Run a safe mass send test: create a tiny campaign with a single custom_list recipient and invoke send_mass_email.
$manager = QvaClick_Admin_Email_Manager::get_instance();

// Create a test campaign
$data = array(
  'campaign_name' => 'TEST-CAMPAIGN-' . time(),
  'subject' => 'Test Subject',
  'content' => 'Hello {{user_name}} - this is a test.',
  'recipient_type' => 'custom_list',
  'recipient_filter' => 'devtest@example.invalid',
  'status' => 'draft'
);
$campaign_id = $manager->create_mass_email($data);
echo "Created campaign: " . print_r($campaign_id, true) . "\n";

$result = $manager->send_mass_email($campaign_id);
echo "Send result: " . print_r($result, true) . "\n";

// Query last outbox entry for campaign
global $wpdb;
$out = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}qvc_email_outbox WHERE reference_id = %d ORDER BY created_at DESC LIMIT 1", $campaign_id));
if ($out) {
  echo "Outbox row: status=" . $out->status . " error=" . substr($out->error_message,0,200) . "\n";
  echo "SMTP debug: " . substr($out->smtp_debug,0,400) . "\n";
}
