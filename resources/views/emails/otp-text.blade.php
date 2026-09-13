{{ $brandName }}

Verify your email

{{ $messageBody }}

Your verification code: {{ $code }}
@if ($showExpiry)
This code expires in {{ $minutes }} minutes.
@endif

If you did not request this code, you can safely ignore this email. Never share your verification code with anyone.

© {{ date('Y') }} {{ $brandName }}. All rights reserved.
