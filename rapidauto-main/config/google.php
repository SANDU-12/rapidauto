<?php
// Google OAuth client IDs are public browser identifiers, not secrets.
// Create a Web application client in Google Cloud Console and paste its ID here.
define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '290666409438-nfqmbo0stlr0bj0sedq95ros93rf46t3.apps.googleusercontent.com');