{!! $bodyText !!}
@if(($isFollowUp ?? false) && ! str_contains((string) $bodyText, 'open-account'))

You can open a business account with us here: https://centralexecutivetransfers.co.uk/open-account
@endif

{{ $sig['name'] ?? 'Matthew Green' }}
{{ $sig['company'] ?? 'Central Executive Transfers' }}
{{ $sig['phone'] ?? '0114 399 8242' }}
{{ $sig['website'] ?? 'www.centralexecutivetransfers.co.uk' }}
