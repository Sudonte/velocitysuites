{{--
    Footer for a length-aware paginated table: "Showing X-Y of Z", a page-size
    selector (10/25/50, keeps every other query parameter such as search and
    filters) and numbered page links. Use with App\Support\PerPage and
    ->paginate(...)->withQueryString() in the controller.
--}}
@props(['paginator'])
@if($paginator->total() > 0)
    <div {{ $attributes->merge(['class' => 'd-flex flex-wrap align-items-center justify-content-between gap-2 w-100 table-pagination']) }}>
        <div class="d-flex align-items-center gap-2 small text-muted">
            <span>Showing {{ $paginator->firstItem() }}&ndash;{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>
            <form method="GET" class="d-flex align-items-center gap-1">
                @foreach(request()->except(['per_page', 'page']) as $key => $value)
                    @if(is_array($value))
                        @foreach($value as $v)
                            <input type="hidden" name="{{ $key }}[]" value="{{ $v }}">
                        @endforeach
                    @else
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach
                <label for="perPage{{ $paginator->getPageName() }}" class="mb-0">Rows</label>
                <select id="perPage{{ $paginator->getPageName() }}" name="per_page" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                    @foreach(\App\Support\PerPage::OPTIONS as $option)
                        <option value="{{ $option }}" {{ $paginator->perPage() === $option ? 'selected' : '' }}>{{ $option }}</option>
                    @endforeach
                </select>
            </form>
        </div>
        @if($paginator->hasPages())
            {{ $paginator->onEachSide(1)->links('pagination::bootstrap-4') }}
        @endif
    </div>
@endif
