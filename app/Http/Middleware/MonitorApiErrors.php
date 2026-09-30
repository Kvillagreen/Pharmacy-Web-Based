<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
class MonitorApiErrors {
    public function handle(Request $request, Closure $next) {
        if (!$request->is('api/*')) return $next($request);
        $id=(string)Str::uuid();$start=microtime(true);
        try {$response=$next($request);}
        catch(\Throwable $error){Log::error('api_exception',['request_id'=>$id,'method'=>$request->method(),'route'=>$request->route()?->uri(),'exception'=>get_class($error)]);throw $error;}
        $response->headers->set('X-Request-ID',$id);
        if($response->getStatusCode()>=500)Log::error('api_failure',['request_id'=>$id,'method'=>$request->method(),'route'=>$request->route()?->uri(),'status'=>$response->getStatusCode(),'duration_ms'=>round((microtime(true)-$start)*1000)]);
        return $response;
    }
}
