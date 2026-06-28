@php
    $brandLogoUrl = $brandLogoUrl ?? \App\Support\BrandLogo::emailUrl();
    $brandCircleLogoUrl = $brandCircleLogoUrl ?? \App\Support\BrandLogo::emailCircleUrl();
@endphp
@if($brandCircleLogoUrl)
<tr>
    <td align="center" style="padding:24px 20px 10px 20px;">
        <img
            src="{{ $brandCircleLogoUrl }}"
            alt="Fit and Sleek"
            width="96"
            height="96"
            style="display:block;width:96px;height:96px;max-width:96px;border:0;border-radius:50%;outline:none;text-decoration:none;"
        />
    </td>
</tr>
@endif
<tr>
    <td align="center" style="padding:{{ $brandCircleLogoUrl ? '0' : '24px' }} 20px 14px 20px;">
        <img
            src="{{ $brandLogoUrl }}"
            alt="Fit and Sleek"
            width="560"
            style="display:block;width:100%;max-width:560px;height:auto;border:0;outline:none;text-decoration:none;"
        />
    </td>
</tr>
