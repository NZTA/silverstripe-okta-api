<?php

// required constants for okta-api module

if (!defined('SS_OKTA_GATEWAY_REST_URL')) {
    user_error('Please ensure the SS_OKTA_GATEWAY_REST_URL constant is defined.');
}

if (!defined('SS_OKTA_API_TOKEN')) {
    user_error('Please ensure the SS_OKTA_API_TOKEN constant is defined.');
}
