{{-- WhatsApp share for one source: message + image. Expects $share (App\Services\SourceShare::build). --}}
@php
    $inr = fn (float $v) => \App\Services\SourceShare::inr($v);
    $cardId = 'share-card-'.\Illuminate\Support\Str::slug($share['name']).'-'.$share['ipo_count'].'-'.$share['apps_total'];
@endphp

@assets
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" defer></script>
    <script>
        // Used by the Share buttons: image at 3x so it stays sharp when zoomed in WhatsApp.
        window.ipoShare = function (cardId, text, phone, filename) {
            return {
                note: '',
                canShare: !!(navigator.share && navigator.canShare),
                waUrl: 'https://wa.me/' + (phone || '') + '?text=' + encodeURIComponent(text),

                flash(message) {
                    this.note = message;
                    setTimeout(() => { this.note = ''; }, 2500);
                },

                async copyText() {
                    try {
                        await navigator.clipboard.writeText(text);
                        this.flash('Text copy thai gayo — WhatsApp ma paste karo');
                    } catch (e) {
                        this.flash('Copy na thayu — "WhatsApp message" button vaparo');
                    }
                },

                async blob() {
                    if (!window.html2canvas) { throw new Error('image tool load thayu nathi'); }
                    const canvas = await html2canvas(document.getElementById(cardId), {
                        scale: 3, backgroundColor: '#ffffff', useCORS: true, logging: false,
                    });
                    return await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
                },

                async copyImage() {
                    try {
                        this.flash('Image bani rahi chhe...');
                        const blob = await this.blob();
                        if (navigator.clipboard && window.ClipboardItem) {
                            await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
                            this.flash('Image copy thai gayi — WhatsApp ma paste (Ctrl+V) karo');
                        } else {
                            this.save(blob);
                        }
                    } catch (e) {
                        this.flash('Copy na thayu — "Download image" vaparo');
                    }
                },

                save(blob) {
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url; a.download = filename; a.click();
                    setTimeout(() => URL.revokeObjectURL(url), 1000);
                    this.flash('Image download thai gayi');
                },

                async download() {
                    try { this.flash('Image bani rahi chhe...'); this.save(await this.blob()); }
                    catch (e) { this.flash('Image na bani: ' + e.message); }
                },

                // Phone: opens the share sheet with the image attached, so WhatsApp can be picked directly.
                async shareImage() {
                    try {
                        const blob = await this.blob();
                        const file = new File([blob], filename, { type: 'image/png' });
                        if (navigator.canShare({ files: [file] })) {
                            await navigator.share({ files: [file], text: text });
                        } else {
                            this.save(blob);
                        }
                    } catch (e) {
                        if (e.name !== 'AbortError') { this.flash('Share na thayu — "Download image" vaparo'); }
                    }
                },
            };
        };
    </script>
@endassets

<div class="px-3 pb-3" x-data="ipoShare(@js($cardId), @js($share['text']), @js($share['phone']), @js($share['filename']))">
    <div class="flex flex-wrap items-center gap-2">
        <a :href="waUrl" target="_blank" rel="noopener"
           class="inline-flex items-center gap-1 rounded-lg bg-success-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-success-500">
            WhatsApp message
            @if (! $share['phone'])<span class="text-xs font-normal opacity-80">(number nathi — contact pasand karo)</span>@endif
        </a>
        <button type="button" x-on:click="copyText()"
                class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:text-gray-200 dark:hover:bg-white/5">Copy text</button>
        <button type="button" x-on:click="copyImage()"
                class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:text-gray-200 dark:hover:bg-white/5">Copy image</button>
        <button type="button" x-on:click="download()"
                class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:text-gray-200 dark:hover:bg-white/5">Download image</button>
        <button type="button" x-show="canShare" x-cloak x-on:click="shareImage()"
                class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-500">Share image (WhatsApp)</button>
        <span x-show="note" x-cloak x-text="note" class="text-xs text-gray-600 dark:text-gray-300"></span>
    </div>

    {{-- The picture that gets shared. Inline styles on purpose: it must look the same on every screen and in dark mode. --}}
    <div style="position:fixed; left:-10000px; top:0; pointer-events:none;" aria-hidden="true">
        <div id="{{ $cardId }}" style="width:720px; background:#ffffff; color:#0f172a; font-family:'Noto Sans Gujarati','Nirmala UI','Shruti','Segoe UI',Arial,sans-serif; line-height:1.35;">
            <div style="background:#059669; color:#ffffff; padding:22px 28px;">
                <div style="font-size:14px; opacity:.9;">IPO હિસાબ · {{ $share['date'] }}</div>
                <div style="font-size:30px; font-weight:700;">{{ $share['name'] }}</div>
            </div>

            <div style="padding:22px 28px;">
                <div style="display:flex; gap:12px;">
                    <div style="flex:1; border:1px solid #e2e8f0; border-radius:12px; padding:12px 14px;">
                        <div style="font-size:12px; color:#64748b;">તમે આપ્યા</div>
                        <div style="font-size:22px; font-weight:700;">{{ $inr($share['given']) }}</div>
                    </div>
                    <div style="flex:1; border:1px solid #e2e8f0; border-radius:12px; padding:12px 14px;">
                        <div style="font-size:12px; color:#64748b;">પાછા આપ્યા</div>
                        <div style="font-size:22px; font-weight:700;">{{ $inr($share['returned']) }}</div>
                    </div>
                    <div style="flex:1; border:2px solid #059669; border-radius:12px; padding:12px 14px;">
                        <div style="font-size:12px; color:#64748b;">હજી અમારી પાસે</div>
                        <div style="font-size:22px; font-weight:700; color:#047857;">{{ $inr($share['due']) }}</div>
                    </div>
                </div>

                @if ($share['profit_earned'] != 0.0)
                    <div style="margin-top:10px; font-size:14px; color:#334155;">
                        નફાનો તમારો ભાગ: <strong>{{ $inr($share['profit_earned']) }}</strong> (બાકી {{ $inr($share['profit_pending']) }})
                    </div>
                @endif

@if ($share['focus_name'])
                    <div style="margin-top:22px; font-size:18px; font-weight:700;">{{ $share['focus_name'] }}</div>
                    <div style="font-size:15px; color:#475569;">કુલ {{ $share['apps_total'] }} અરજી · <strong style="color:#0f172a;">{{ $inr($share['amount_total']) }}</strong></div>
                @else
                    <div style="margin-top:22px; font-size:16px; font-weight:700;">
                        IPO માં ભર્યા — કુલ {{ $share['ipo_count'] }} IPO · {{ $share['apps_total'] }} અરજી · {{ $inr($share['amount_total']) }}
                    </div>
                    <table style="width:100%; border-collapse:collapse; margin-top:8px; font-size:15px;">
                        @foreach ($share['ipos'] as $ipo)
                            <tr style="border-bottom:1px solid #e2e8f0;">
                                <td style="padding:7px 0;">{{ $ipo['name'] }}</td>
                                <td style="padding:7px 0; text-align:right; color:#475569;">{{ $ipo['apps'] }} અરજી</td>
                                <td style="padding:7px 0; text-align:right; font-weight:700;">{{ $inr($ipo['amount']) }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif

                @if ($share['focus_name'] && $share['focus_rows']->isNotEmpty())
                    <div style="margin-top:18px; font-size:16px; font-weight:700;">કોના નામે ભર્યું (PAN સાથે)</div>
                    <table style="width:100%; border-collapse:collapse; margin-top:8px; font-size:15px;">
                        @foreach ($share['focus_rows'] as $i => $row)
                            <tr style="border-bottom:1px solid #e2e8f0;">
                                <td style="padding:6px 0; width:34px; color:#64748b;">{{ $i + 1 }}</td>
                                <td style="padding:6px 0;">
                                    {{ $row['name'] }}
                                    @if ($row['pan'])<div style="font-size:12px; color:#64748b; letter-spacing:.5px;">{{ $row['pan'] }}</div>@endif
                                </td>
                                <td style="padding:6px 0; text-align:right; color:#475569;">{{ $row['lots'] ? $row['lots'].' લોટ' : '' }}</td>
                                <td style="padding:6px 0; text-align:right; font-weight:700;">{{ $inr($row['amount']) }}</td>
                            </tr>
                        @endforeach
                        <tr>
                            <td colspan="3" style="padding:10px 0 0; font-weight:700;">કુલ ({{ $share['focus_rows']->count() }} અરજી)</td>
                            <td style="padding:10px 0 0; text-align:right; font-weight:700; font-size:17px;">{{ $inr($share['focus_amount']) }}</td>
                        </tr>
                    </table>
                @endif
            </div>
        </div>
    </div>
</div>
