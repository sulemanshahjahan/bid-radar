<?php

namespace App\Http\Controllers;

use App\Services\WebPush\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Browser push subscriptions for the logged-in owner (Settings page buttons, public/push.js). */
class PushController extends Controller
{
    public function key(WebPush $push): JsonResponse
    {
        return response()->json(['key' => $push->publicKey()]);
    }

    public function subscribe(Request $request, WebPush $push): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url:https', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:100'],
        ]);

        $push->subscribe(['endpoint' => $data['endpoint'], 'p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth']]);

        return response()->json(['devices' => count($push->subscriptions())]);
    }

    public function unsubscribe(Request $request, WebPush $push): JsonResponse
    {
        $push->unsubscribe((string) $request->validate(['endpoint' => ['required', 'string', 'max:1000']])['endpoint']);

        return response()->json(['devices' => count($push->subscriptions())]);
    }

    public function test(WebPush $push): JsonResponse
    {
        $sent = $push->send([
            'title' => 'BidRadar test',
            'body' => 'Push notifications work on this device.',
            'url' => route('projects.index'),
            'tag' => 'test',
        ]);

        return response()->json(['sent' => $sent, 'devices' => count($push->subscriptions())]);
    }
}
