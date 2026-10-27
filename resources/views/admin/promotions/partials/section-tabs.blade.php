{{--
    Promos & Discounts: one page, two tabs. Promos (Admin\PromotionManagementController) bundle free amenities
    and/or a discount off the room rate; Discounts (Admin\DiscountManagementController) are ID-checked (Senior
    Citizen, PWD, ...). Each tab keeps its own create/edit pages.
    @param string $active  'promos' | 'discounts'
--}}
<x-page-header icon="fas fa-tags" title="Promos & Discounts">
    <x-slot:actions>
        @if($active === 'promos')
            <a href="{{ route('admin.promotions.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Add Promo</a>
        @else
            <a href="{{ route('admin.discounts.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Add Discount</a>
        @endif
    </x-slot:actions>
</x-page-header>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link {{ $active === 'promos' ? 'active' : '' }}" href="{{ route('admin.promotions.index') }}" @if($active === 'promos') aria-current="page" @endif>
            <i class="fas fa-tag"></i> Promos
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $active === 'discounts' ? 'active' : '' }}" href="{{ route('admin.discounts.index') }}" @if($active === 'discounts') aria-current="page" @endif>
            <i class="fas fa-id-card"></i> Discounts
        </a>
    </li>
</ul>
<p class="text-muted small mb-3">
    @if($active === 'promos')
        Promos give free amenities, a discount off the room rate, or both. A promo for one room type only discounts that room type's rate.
    @else
        ID-checked discounts (Senior Citizen, PWD, Student, etc.), applied by the receptionist after verifying the guest's ID. They come off the whole bill.
    @endif
</p>
