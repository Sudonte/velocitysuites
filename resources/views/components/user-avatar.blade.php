{{--
    Profile picture if the account has one, else a circular badge with the
    account's two-letter initials (User::initials) - never hardcoded, always
    derived from the authenticated account's own first/last name. Reused by
    the header, the System Administrator profile page, and the guest profile
    page so all three stay visually and behaviorally identical.
--}}
@props(['user', 'size' => 40])

@if($user->profile_picture_url)
    <img src="{{ $user->profile_picture_url }}" alt="{{ $user->full_name }}"
         {{ $attributes->merge(['class' => 'user-avatar-img']) }}
         style="width: {{ $size }}px; height: {{ $size }}px;">
@else
    <span {{ $attributes->merge(['class' => 'user-avatar-initials']) }}
          style="width: {{ $size }}px; height: {{ $size }}px; font-size: {{ round($size * 0.4) }}px;">
        {{ $user->initials }}
    </span>
@endif
