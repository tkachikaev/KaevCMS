@if (session('status') || session('warning') || $errors->any())
    <div class="auth-inline-feedback">
        @if (session('status'))
            <div class="public-flash public-flash-success" role="status">{{ session('status') }}</div>
        @endif
        @if (session('warning'))
            <div class="public-flash public-flash-warning" role="alert">{{ session('warning') }}</div>
        @endif
        @if ($errors->any())
            <div class="public-flash public-flash-error" role="alert">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif
    </div>
@endif
