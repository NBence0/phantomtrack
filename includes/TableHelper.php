<?php
class TableHelper {
    private $columns = [];
    private $currentSort;
    private $currentDirection;
    private $baseUrl;

    /**
     * @param string $currentSort Aktuálisan rendezett oszlop neve (pl. 'created_at')
     * @param string $currentDirection Aktuális irány ('asc' vagy 'desc')
     * @param string $baseUrl Az alap URL (opcionális, ha üres, a jelenlegit használja)
     */
    public function __construct($currentSort = '', $currentDirection = 'desc', $baseUrl = '') {
        $this->currentSort = $currentSort;
        $this->currentDirection = strtolower($currentDirection);
        $this->baseUrl = $baseUrl;
    }

    /**
     * Oszlop hozzáadása
     * @param string $key Az adatbázis mező neve (vagy egyedi azonosító)
     * @param string $label A fejlécben megjelenő szöveg
     * @param bool $sortable Rendezhető-e az oszlop?
     * @param string $width Opcionális szélesség (pl. '150px' vagy '10%')
     */
    public function addColumn($key, $label, $sortable = true, $width = '') {
        $this->columns[] = [
            'key' => $key,
            'label' => $label,
            'sortable' => $sortable,
            'width' => $width
        ];
    }

    /**
     * A teljes <thead> HTML generálása
     */
    public function render() {
        echo '<thead class="smart-table-head">';
        echo '<tr>';

        foreach ($this->columns as $col) {
            $class = 'smart-th';
            $attributes = '';
            $icon = '';

            // Stílus beállítások
            if ($col['width']) {
                $attributes .= ' style="width:' . $col['width'] . '"';
            }

            // Rendezés logika
            if ($col['sortable']) {
                $class .= ' sortable';
                $attributes .= ' data-column="' . $col['key'] . '"';
                
                // Aktív rendezés vizsgálata
                if ($this->currentSort === $col['key']) {
                    $class .= ' active-' . $this->currentDirection;
                    // Ikon kiválasztása
                    $icon = ($this->currentDirection === 'asc') 
                        ? '<i class="fas fa-sort-up"></i>' 
                        : '<i class="fas fa-sort-down"></i>';
                } else {
                    // Inaktív állapot ikonja (halványan)
                    $icon = '<i class="fas fa-sort text-muted" style="opacity:0.3;"></i>';
                }
            }

            echo '<th class="' . $class . '"' . $attributes . '>';
            echo '<div class="th-content">';
            echo '<span>' . htmlspecialchars($col['label']) . '</span>';
            if ($col['sortable']) {
                echo '<span class="sort-icon">' . $icon . '</span>';
            }
            echo '</div>';
            echo '</th>';
        }

        echo '</tr>';
        echo '</thead>';
    }
}
?>