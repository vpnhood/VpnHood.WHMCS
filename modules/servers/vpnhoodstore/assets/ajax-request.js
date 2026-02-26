document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('getPremiumCode');
    const resultBox = document.getElementById('resultBox');

    if (btn && resultBox) {
        btn.addEventListener('click', function () {
            resultBox.innerHTML = '⏳ Fetching code...';

            fetch(window.location.href, {
                method: 'GET',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
                .then(response => response.text())
                .then(data => {
                    const formatedCode = data.replace(/(\d{4})(?=\d)/g, "$1-");
                    resultBox.innerHTML = `<div class="alert alert-success">${formatedCode}</div>`;
                })
                .catch(error => {
                    resultBox.innerHTML = `<div class="alert alert-danger">❌ Error: ${error}</div>`;
                    console.error('Fetch error:', error);
                });

        });
    }
});