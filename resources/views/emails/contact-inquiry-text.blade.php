NEW INQUIRY — {{ $ticket }}
{{ $date }}

NAME:         {{ $name }}
EMAIL:        {{ $email }}
COMPANY:      {{ $company !== '' ? $company : '—' }}
PROJECT TYPE: {{ $type !== '' ? $type : '—' }}
COPY TO SELF: {{ $copyToSelf ? 'Yes' : 'No' }}

MESSAGE:
{{ $body }}
