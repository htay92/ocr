<form method="POST" action="{{ route('templates.destroy', $profile) }}" style="flex:0 0 auto; margin-left:auto"
      onsubmit="return confirm(@js(__('Delete the template ":name"? Attendance already imported with it stays as it is.', ['name' => $profile->name])))">
    @csrf
    @method('DELETE')
    <button class="button danger small" type="submit">{{ __('Delete') }}</button>
</form>
