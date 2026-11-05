@php /** @var \Laravel\Boost\Install\GuidelineAssist $assist */ @endphp
# Flamix FlamixML

Plain-PHP (not Laravel) FlamixML exchange between a CMS site and Bitrix24, plus `Flamix\FlamixML\OneC\FlamixML`, the XML writer/reader the Inventory app uses directly. It owns the request protocol; it does not store products or talk to Bitrix24 itself.

- Do not hand-roll FlamixML: `FlamixML::classifier()` and `FlamixML::offers()` to write, `FlamixML::read*()` to read.
- Site side: extend `Flamix\FlamixML\Init`, override `actionCheck()` and the handlers. Every `flamixml_response*()` ends the request with `die()`, so nothing after it runs.
- The `flamixml_*()` helpers exist only after `Init::init($dir)`; they are not in the Composer autoload.
- Full docs: `vendor/flamix/flamixml/docs/*.md` (setup, export, import, xml).

Detail lives in the skill: `flamixml-exchange`.
