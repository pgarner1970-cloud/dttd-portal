(function(){
  function ready(fn){
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function makeQrUrl(value, size){
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' + size + 'x' + size + '&margin=16&data=' + encodeURIComponent(value);
  }

  ready(function(){
    document.querySelectorAll('.event-qr-body').forEach(function(panel){
      const url = panel.dataset.qrUrl || '';
      const canvas = panel.querySelector('.event-qr-canvas');
      if (!url || !canvas) return;

      const size = canvas.width || 220;
      const img = document.createElement('img');
      img.className = 'event-qr-image';
      img.alt = 'Event QR code';
      img.src = makeQrUrl(url, size);
      img.dataset.qrUrl = img.src;
      canvas.replaceWith(img);

      const printBtn = panel.querySelector('.qr-print-btn');
      if (printBtn) {
        printBtn.addEventListener('click', function(){
          const printWindow = window.open('', '_blank', 'width=720,height=780');
          if (!printWindow) return;
          printWindow.document.write(
            '<!doctype html><html><head><title>Event QR Code</title>' +
            '<style>body{font-family:Arial,sans-serif;text-align:center;padding:40px;} img{width:360px;height:360px;} .code{font-size:42px;font-weight:800;margin:20px 0;} .url{font-size:14px;color:#444;word-break:break-all;}</style>' +
            '</head><body>' +
            '<h1>Scan to request a song</h1>' +
            '<img src="' + img.src + '" alt="QR Code">' +
            '<div class="code">' + (panel.querySelector('.event-code-panel strong')?.textContent || '') + '</div>' +
            '<div class="url">' + url + '</div>' +
            '<script>window.onload=function(){window.print();}</script>' +
            '</body></html>'
          );
          printWindow.document.close();
        });
      }

      const downloadBtn = panel.querySelector('.qr-download-btn');
      if (downloadBtn) {
        downloadBtn.addEventListener('click', function(){
          const a = document.createElement('a');
          a.href = img.src;
          a.target = '_blank';
          a.download = 'event-qr-code.png';
          document.body.appendChild(a);
          a.click();
          a.remove();
        });
      }

      const copyBtn = panel.querySelector('.qr-copy-btn');
      if (copyBtn) {
        copyBtn.addEventListener('click', async function(){
          try {
            await navigator.clipboard.writeText(url);
            copyBtn.textContent = 'Copied';
            setTimeout(function(){ copyBtn.textContent = 'Copy Link'; }, 1500);
          } catch (e) {
            window.prompt('Copy this link:', url);
          }
        });
      }
    });
  });
})();
