@if(auth()->check() && auth()->user()->role === 'guest')
@include('errors.404-guest')
@else
@include('errors.404-plain')
@endif
