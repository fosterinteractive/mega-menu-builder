<?php

namespace Drupal\mega_menu_builder;

/**
 * The mega menu builder.
 */
class MegaMenuBuilder {

  const POSITION_BEFORE = 'before';
  const POSITION_AFTER = 'after';
  const POSITION_SPLIT = 'split';
  const POSITION_EQUAL = 'equal';

  /**
   * The number of columns.
   *
   * @var int
   */
  protected $cols = 5;

  /**
   * A boolean to indicate if there is a split column in this panel.
   *
   * @var bool
   */
  protected $split = FALSE;

  /**
   * The split column amount of items.
   *
   * @var int
   */
  protected $splitAmount = 0;

  /**
   * The split column menu id.
   *
   * @var string
   */
  protected $splitId = '';

  /**
   * The ideal column split.
   *
   * @var int
   */
  protected $multiplier = 2;

  /**
   * The panel data.
   *
   * @var array
   */
  protected $panelData = [];

  /**
   * The total amount of menu items.
   *
   * @var int
   */
  protected $total = 0;

  /**
   * Build a mega menu panel.
   *
   * Assumes builder from individual menu items. This is useful if wanting to
   * generate panels in a custom theme where col sizes can vary based on
   * custom logic.
   */
  public function build($item, int $cols) {
    $columns_data = [];
    // Reset variables on build.
    $this->split = FALSE;
    $this->splitId = '';
    $this->splitAmount = 0;
    $this->panelData = [];
    $this->total = 0;

    // If there are no child menu items then exit. It is only 1 menu item and we
    // can build a mega menu panel from 1 menu item.
    if (empty($item['below'])) {
      return $columns_data;
    }

    // Make sure cols are a number between 1 and 5.
    $cols = $cols <= 0 ? 5 : $cols;
    $cols = $cols > 5 ? 5 : $cols;
    $this->cols = $cols;
    $columns_data['number_of_columns'] = $cols;

    // Reverse the child menu items. This is done for when we calcuate the ideal
    // column distibution of items the last column always ends up with more
    // baecause of how leftovers are done. If we reverse the items and then
    // reverse them back when we are done then the first column will appear
    // bigger which is perferred by clients.
    $child_items = array_reverse($item['below']);

    // Determine if there is a split column in this menu.
    $this->checkSplit($child_items);

    // Build a matrix of all the data for each menu item in the mega menu
    // panel. This will be used to determine the optimal column layout.
    $this->calculatePanel($child_items);

    $columns = $this->constructColumns($child_items);

    // Deal with reversing the array.
    // Reverse the items in columns so they are in the correct order.
    foreach ($columns as $n => $column) {
      $columns[$n] = array_reverse($column);
    }
    // Reverse the columns back to the right order.
    $columns = array_reverse($columns);
    // Reindex our columns to start at 1 instead of 0.
    $columns = array_combine(range(1, count($columns)), array_values($columns));

    // After reversing the array back we need to find which column is the split
    // again.
    if ($this->split == TRUE) {
      foreach ($columns as $col => $items) {
        if (in_array($this->splitId, $items)) {
          $columns_data['split_col'] = $col;
        }
      }
    }
    // Add the columns to the column data.
    $columns_data['columns'] = $columns;

    return $columns_data;
  }

  /**
   * Build all mega menu panels.
   *
   * Assumes all menu items are passed and will construct all panels on the
   * reference variables on hook_menu_preprocess.
   */
  public function buildAll(&$variables, int $cols = 5) {
    // @todo Add this later. It's just an improvement to the module that this
    // will work on any site with a default template in this module.
  }

  /**
   * Check if there is a split column in this menu item.
   *
   * Determine if a single menu item it larger then all other menu items by a
   * factor and if it is mark the menu item to be split into columns.
   *
   * @param array $items
   *   An array of menu items.
   *
   * @return bool
   *   Either TRUE or FALSE if there is a split column.
   */
  protected function checkSplit(array $items): bool {
    // Keep track of the total items in a split column.
    $split_amount = 0;
    // Keep track of the menu id of a split column.
    $split_id = '';
    // Keep track of the total amount of items for this mega menu panel.
    $total = 0;
    // First we need to find the single menu item that has the most links and
    // compare that to the total amount of all the menu items.
    foreach ($items as $id => $item) {
      // Count all child menu items and 1 for the parent menu item.
      $amount = !empty($item['below']) ? count($item['below']) + 1 : 1;
      // Add the amount to our total amount of links.
      $total = $total + $amount;
      // If this is our largest column so far keep track of it.
      if ($amount > $split_amount) {
        $split_amount = $amount;
        $split_id = $id;
      }
    }

    $this->total = $total;

    // Only columns bigger then 2 can have a split column.
    if ($this->cols <= 2) {
      return FALSE;
    }

    // If this is a 3 column menu item and the split has items before it or
    // after then it cannot be split as menu items cannot be moved around.
    if ($this->cols == 3) {
      $before = array_key_first($items) != $split_id;
      $after  = array_key_last($items) != $split_id;
      // Has items before and after then don't split.
      if ($before & $after) {
        return FALSE;
      }
    }

    // Get the difference between the total and the largest menu item amount.
    $diff = $total - $split_amount;
    // Check the largest menu item we found to see if it is greater then the
    // total amount of menu items by a factor. If it is then we should split
    // this column.
    if ($split_amount >= ($diff * $this->multiplier)) {
      $this->split = TRUE;
      $this->splitId = $split_id;
      $this->splitAmount = $split_amount;

      return TRUE;
    }

    return FALSE;
  }

  /**
   * Create a matrix of data for each menu item.
   *
   * This data will be used to construct the ideal column layout for a mega menu
   * panel.
   *
   * @param array $items
   *   The menu items.
   *
   * @return array
   *   An array of panel data.
   */
  protected function calculatePanel(array $items): array {
    $panel_data = [];
    // The found variable is used to add the position data to an item. The
    // position data is used to mark if a menu item is before or after a split
    // column.
    $found = FALSE;
    // How many items are before the split column.
    $amount_before = 0;
    // How many items are after the split column.
    $amount_after = 0;
    $i = 0;
    foreach ($items as $id => $item) {
      $panel_data['items'][$i] = [
        'menu_id' => $id,
        'amount' => !empty($item['below']) ? count($item['below']) + 1 : 1,
        'position' => '',
      ];

      if ($this->split) {
        if ($this->splitId == $id) {
          $found = TRUE;
          $panel_data['items'][$i]['position'] = self::POSITION_SPLIT;
        }
        elseif ($found == FALSE) {
          $panel_data['items'][$i]['position'] = self::POSITION_BEFORE;
          $amount_before = $amount_before + $panel_data['items'][$i]['amount'];
        }
        elseif ($found == TRUE) {
          $panel_data['items'][$i]['position'] = self::POSITION_AFTER;
          $amount_after = $amount_after + $panel_data['items'][$i]['amount'];
        }
      }

      $i++;
    }

    // Add the total amount of links before and after the split to the data.
    $panel_data['amount_before'] = $amount_before;
    $panel_data['amount_after'] = $amount_after;

    // If the cols remaining after the split are more then 2 then determine if
    // a 2 col should be added before or after the split depending on if there
    // are more items before or after.
    $panel_data['position'] = NULL;
    if ($this->split == TRUE) {
      if ($this->cols - $this->multiplier > 2) {
        // If the split is at the begining or the end we don't need to do
        // anything special for it. The default calculation will work otherwise
        // set the position.
        if (
          $amount_before != 0 &&
          $amount_after != 0
        ) {
          if ($amount_before > $amount_after) {
            $panel_data['position'] = self::POSITION_BEFORE;
          }
          elseif ($amount_after > $amount_before) {
            $panel_data['position'] = self::POSITION_AFTER;
          }
        }
      }
      // If there are 2 or any less columns left over after the split then the
      // items before and after the split go into a column before or after it
      // based on the position of the split menu item.
      else {
        if (
          $amount_before != 0 &&
          $amount_after != 0
        ) {
          $panel_data['position'] = self::POSITION_EQUAL;
        }
      }
    }

    $this->panelData = $panel_data;
    return $panel_data;
  }

  /**
   * Constructs columns data.
   *
   * @param array $items
   *   The menu items.
   *
   * @return array
   *   An array of columns data.
   */
  protected function constructColumns(array $items): array {
    $columns = [];
    // Amount of cols to create minus a split column if there is one.
    $cols = $this->split == TRUE ? $this->cols - 1 : $this->cols;
    // The panel data.
    $panel_data = $this->panelData;
    // Record processed menu items.
    $processed_items = [];
    // Indicates if a column should be skiped. Used with split columns.
    $skip = FALSE;
    // Used for split columns (5 cols) to determine if 2 cols should be before
    // or after the split.
    $col_position = $panel_data['position'];
    $col_position_amount = $col_position == self::POSITION_BEFORE ? $panel_data['amount_before'] : $panel_data['amount_after'];
    // The total amount of menu items available, subtracting the largest col
    // amount if we have a split column.
    $col_dividend = $this->split == TRUE ? $this->total - $this->splitAmount : $this->total;
    // How many menu items are left to distribute to our remaining columns after
    // a column is constructed.
    $remainder = $col_dividend;
    // The number of columns to divide our links by to determine a balanced
    // ideal layout, removing the columns used for the split.
    $col_disvisor = $this->split == TRUE ? $this->cols - $this->multiplier : $cols;
    // Change the dividend and divisor for a column with position either before
    // or after.
    if (
      $col_position == self::POSITION_BEFORE ||
      $col_position == self:: POSITION_AFTER
    ) {
      $col_dividend = $col_position_amount;
      $col_disvisor = 2;
    }

    // Calculate the ideal amount of links in each column. We take the total
    // links of the level 1 menu item (menu level 2 + 3) and divide by the
    // amount of columns.
    $column_count = (int) ceil($col_dividend / $col_disvisor);
    // Loop through the amount of columns there are.
    $i = 1;
    foreach (range(1, $cols) as $col) {
      // The amount of menu items added to the column.
      $item_count = 0;
      // Skip this column as we have already placed the split column in it.
      if ($skip) {
        // Reset the skip variable to capture the rest of the columns.
        $skip = FALSE;
        continue;
      }

      foreach ($panel_data['items'] as $item) {
        $menu_id = $item['menu_id'];
        $amount = $item['amount'];
        $position = $item['position'];

        // If this level 2 item has been processed already (Set to a column)
        // then skip processing it for this column.
        if (isset($processed_items[$menu_id])) {
          continue;
        }

        // Indicates if a menu item should be added to the column.
        $process = FALSE;

        // If this is a split column add it to it's own single column.
        if ($this->splitId == $menu_id) {
          // If this column is empty add the split.
          if (empty($columns[$col])) {
            $n = $col;
          }
          // If the column already has items in it add the split column to the
          // next column and skip process the next column.
          else {
            $n = $col + 1;
            $skip = TRUE;
          }

          $columns[$n][$menu_id] = $menu_id;
          $processed_items[$menu_id] = $menu_id;

          break;
        }
        // If there is only 1 column before and after a split column just add
        // all items to the column.
        elseif ($col_position == self::POSITION_EQUAL) {
          $process = TRUE;
        }
        // If the col position is before but the item position is after fill
        // up the column with all the menu items that are after since this will
        // be a 1 col after a split column.
        elseif (
          $col_position == self::POSITION_BEFORE &&
          $position == self::POSITION_AFTER
        ) {
          $process = TRUE;
        }
        // If the col position is after but the item position is before fill
        // up the column with all the menu items that are before since this will
        // be a 1 col before a split column.
        elseif (
          $col_position == self::POSITION_AFTER &&
          $position == self::POSITION_BEFORE
        ) {
          $process = TRUE;
        }
        elseif (
          $col_position == self::POSITION_BEFORE ||
          $col_position == self::POSITION_AFTER
        ) {
          if (empty($columns[$col])) {
            $process = TRUE;
          }
          elseif (($item_count + $amount) <= $column_count) {
            $process = TRUE;
            // Update the item count.
            $item_count = $item_count + $amount;
          }
          else {
            $remainder = $remainder - $item_count;
            $column_count = (int) ceil($remainder / 1);
            // Break the loop and start the next column.
            break;
          }
        }
        // If this column is empty then we should add the next menu item to it.
        elseif (empty($columns[$col])) {
          $process = TRUE;
          // Update the item count.
          $item_count = $item_count + $amount;
        }
        elseif (($item_count + $amount) <= $column_count) {
          $process = TRUE;
          // Update the item count.
          $item_count = $item_count + $amount;
        }

        // If this section is marked for process then add it to the column
        // data.
        if ($process == TRUE) {
          $columns[$col][$menu_id] = $menu_id;
          $processed_items[$menu_id] = $menu_id;
        }
        elseif ($process == FALSE) {
          $cols_remaining = $col_disvisor - $i;
          if ($cols_remaining > 0) {
            $remainder = $remainder - $item_count;
            $column_count = (int) ceil($remainder / $cols_remaining);
            $i++;
          }
          // Break the loop and start the next column.
          break;
        }
      }
    }

    return $columns;
  }

}
