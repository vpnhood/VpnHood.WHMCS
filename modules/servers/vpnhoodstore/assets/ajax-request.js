document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('getPremiumCode');
    const resultBox = document.getElementById('resultBox');

    if (btn && resultBox) {
        btn.addEventListener('click', function () {
            resultBox.innerHTML = '⏳ Fetching data...';

            fetch(window.location.href, {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(response => {
                    // 1. Get the filename from header
                    let fileName = 'access_codes.csv'; // Default fallback
                    const disposition = response.headers.get('Content-Disposition');

                    if (disposition && disposition.includes('filename=')) {
                        const filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/;
                        const matches = filenameRegex.exec(disposition);
                        if (matches != null && matches[1]) {
                            fileName = matches[1].replace(/['"]/g, '');
                        }
                    }

                    const contentType = response.headers.get('content-type');

                    // Check if it's CSV
                    if (contentType && contentType.toLowerCase().includes('text/csv')) {
                        return response.blob().then(blob => ({ type: 'file', data: blob, fileName: fileName }));
                    } else {
                        return response.text().then(text => ({ type: 'text', data: text }));
                    }
                })
                .then(res => {
                    if (res.type === 'file') {
                        const url = window.URL.createObjectURL(res.data);
                        const a = document.createElement('a');
                        a.style.display = 'none';
                        a.href = url;

                        // Try to get filename from header or use default
                        a.download = res.fileName;
                        document.body.appendChild(a);
                        a.click();

                        // Clean up
                        setTimeout(() => {
                            window.URL.revokeObjectURL(url);
                            document.body.removeChild(a);
                        }, 100);

                        resultBox.innerHTML = '<div class="alert alert-success">✅ File downloaded.</div>';
                    } else {
                        const formattedCode = res.data.replace(/(\d{4})(?=\d)/g, "$1-");
                        resultBox.innerHTML = `<div class="alert alert-success">${formattedCode}</div>`;
                    }
                })
                .catch(error => {
                    resultBox.innerHTML = `<div class="alert alert-danger">❌ Error: ${error.message}</div>`;
                    console.error('Fetch error:', error);
                });
        });
    }
});