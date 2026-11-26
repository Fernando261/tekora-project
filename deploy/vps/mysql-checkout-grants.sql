GRANT SELECT, INSERT ON tekora.integrated_checkout_attempts TO 'tekora_app'@'localhost';
GRANT UPDATE (status, received_at) ON tekora.integrated_checkout_attempts TO 'tekora_app'@'localhost';
