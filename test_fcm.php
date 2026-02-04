<?php
use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use Illuminate\Support\Facades\Http;
use App\Models\User;

// Target Driver ID 7
$driver = User::find(7);
$token = $driver->fcm_token;

echo "Targeting Driver: " . $driver->name . "\n";
echo "Token: " . $token . "\n";

$credentialsPath = storage_path('app/firebase_credentials.json');
$scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
$credentials = new ServiceAccountCredentials($scopes, $credentialsPath);
$authToken = $credentials->fetchAuthToken(HttpHandlerFactory::build());
$accessToken = $authToken['access_token'];

echo "Access Token Generated.\n";

$json = json_decode(file_get_contents($credentialsPath), true);
$projectId = $json['project_id'];

$response = Http::withHeaders([
    'Authorization' => 'Bearer ' . $accessToken,
    'Content-Type'  => 'application/json',
])->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
    'message' => [
        'token' => $token,
        'notification' => [
            'title' => 'Test Notification',
            'body' => 'This is a test from Tinker.',
        ],
        'android' => [
            'priority' => 'HIGH',
            'notification' => [
                 'channel_id' => 'high_importance_channel',
                 'default_sound' => true
            ]
        ]
    ]
]);

echo "Response Status: " . $response->status() . "\n";
echo "Response Body: " . $response->body() . "\n";
