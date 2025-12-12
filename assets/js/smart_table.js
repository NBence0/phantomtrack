document.addEventListener('DOMContentLoaded', function() {
    // Eseményfigyelő az összes "sortable" osztályú TH elemre
    const headers = document.querySelectorAll('.smart-th.sortable');

    headers.forEach(header => {
        header.addEventListener('click', function() {
            const column = this.getAttribute('data-column');
            
            // Jelenlegi URL paraméterek lekérése
            const urlParams = new URLSearchParams(window.location.search);
            
            // Aktuális rendezés és irány meghatározása
            const currentSort = urlParams.get('sort');
            const currentDir = urlParams.get('dir') || 'desc'; // Alapértelmezett DESC

            let newDir = 'desc';

            // Ha ugyanarra az oszlopra kattintottunk, fordítsuk meg az irányt
            if (currentSort === column) {
                newDir = (currentDir === 'desc') ? 'asc' : 'desc';
            } else {
                // Ha új oszlop, akkor alapértelmezetten DESC (vagy ASC, ahogy tetszik)
                newDir = 'desc'; 
            }

            // Frissítjük a paramétereket
            urlParams.set('sort', column);
            urlParams.set('dir', newDir);
            
            // Ha van lapozás (page), azt érdemes 1-re állítani rendezés váltáskor
            if (urlParams.get('page')) {
                urlParams.set('page', 1);
            }

            // Újratöltés az új paraméterekkel
            window.location.search = urlParams.toString();
        });
    });
});