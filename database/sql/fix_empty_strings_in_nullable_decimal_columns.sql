-- Fix empty strings in nullable decimal/numeric columns
-- Prevents Brick\Math\NumberFormatException when Laravel casts these values
-- Safe to run multiple times (idempotent)
--
-- Uses CAST(column AS CHAR) to avoid MySQL "Truncated incorrect DECIMAL value"
-- errors when comparing decimal columns to empty strings.

-- event_classes.entry_fee
UPDATE event_classes SET entry_fee = NULL WHERE CAST(entry_fee AS CHAR) = '';

-- events.hook_points_multiplier (NOT NULL column with default 1.0 - reset to default)
UPDATE events SET hook_points_multiplier = 1.0 WHERE CAST(hook_points_multiplier AS CHAR) = '';

-- class_participants - nullable decimal fields
UPDATE class_participants SET distance_pulled = NULL WHERE CAST(distance_pulled AS CHAR) = '';
UPDATE class_participants SET pulloff_distance = NULL WHERE CAST(pulloff_distance AS CHAR) = '';
UPDATE class_participants SET points_awarded = NULL WHERE CAST(points_awarded AS CHAR) = '';
UPDATE class_participants SET show_points_earned = NULL WHERE CAST(show_points_earned AS CHAR) = '';
UPDATE class_participants SET hook_points_earned = NULL WHERE CAST(hook_points_earned AS CHAR) = '';
UPDATE class_participants SET placement_points_earned = NULL WHERE CAST(placement_points_earned AS CHAR) = '';

-- points_calculation_results - nullable decimal fields
UPDATE points_calculation_results SET points_before = NULL WHERE CAST(points_before AS CHAR) = '';
UPDATE points_calculation_results SET points_after = NULL WHERE CAST(points_after AS CHAR) = '';
UPDATE points_calculation_results SET show_points_before = NULL WHERE CAST(show_points_before AS CHAR) = '';
UPDATE points_calculation_results SET show_points_after = NULL WHERE CAST(show_points_after AS CHAR) = '';
UPDATE points_calculation_results SET hook_points_before = NULL WHERE CAST(hook_points_before AS CHAR) = '';
UPDATE points_calculation_results SET hook_points_after = NULL WHERE CAST(hook_points_after AS CHAR) = '';
UPDATE points_calculation_results SET placement_points_before = NULL WHERE CAST(placement_points_before AS CHAR) = '';
UPDATE points_calculation_results SET placement_points_after = NULL WHERE CAST(placement_points_after AS CHAR) = '';
