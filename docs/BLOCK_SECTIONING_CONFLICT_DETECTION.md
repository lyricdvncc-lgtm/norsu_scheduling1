# Block Sectioning Conflict Detection

## Overview

The Block Sectioning Conflict Detection feature ensures that students in the same year level and section cannot be scheduled for multiple subjects at the same time.

## Problem Statement

In a **block sectioning system**, students in the same year level and section take ALL their subjects together as a cohort. For example:

- **3rd Year Section B** students take:
  - ITS 306
  - ITS 307
  - ITS 308
  - etc.

If two subjects (e.g., ITS 306 and ITS 307) are both scheduled for **Section B** at the **same time**, even with:
- ✅ Different faculty (no faculty conflict)
- ✅ Different rooms (no room conflict)

This creates a **STUDENT CONFLICT** because Section B students cannot physically attend both classes simultaneously.

## Example Scenario

### Conflict Case:
```
ITS 306 Section B
- Faculty: Instructor A
- Room: Room 201
- Time: M-T 4:00-5:30 PM
- Year Level: 3

ITS 307 Section B  
- Faculty: Instructor B
- Room: Room 202
- Time: M-T 4:00-5:30 PM
- Year Level: 3
```

**Result**: ❌ BLOCK SECTIONING CONFLICT
- 3rd Year Section B students are assigned to BOTH subjects
- They cannot attend both at the same time
- System will detect and flag this conflict

### No Conflict Case:
```
ITS 306 Section B
- Time: M-T 4:00-5:30 PM
- Year Level: 3

ITS 307 Section A
- Time: M-T 4:00-5:30 PM  
- Year Level: 3
```

**Result**: ✅ NO CONFLICT
- Different sections (B vs A)
- Students in Section B won't be enrolled in Section A courses

## Implementation Details

### Database Schema

The detection relies on the following entities:

1. **Schedule** - Contains the scheduled class information
   - `section` - The section identifier (A, B, C, etc.)
   - `subject_id` - The subject being taught
   - `curriculum_subject_id` - Links to curriculum term for year level
   - `day_pattern`, `start_time`, `end_time` - Schedule timing

2. **CurriculumSubject** - Links subjects to curriculum terms
   - `curriculum_term_id` - References the term

3. **CurriculumTerm** - Defines the academic term
   - `year_level` - The year level (1, 2, 3, 4)
   - `semester` - The semester

### Conflict Detection Logic

The `checkBlockSectioningConflicts()` method in `ScheduleConflictDetector` service:

1. **Extracts year level** from the schedule's curriculum subject
2. **Queries for other schedules** with:
   - Same section
   - Same year level
   - **Different subject** (key difference from regular section conflicts)
   - Same academic year and semester
   - Active status
3. **Checks for overlaps**:
   - Day pattern overlap (e.g., M-T overlaps with M-T)
   - Time overlap (4:00-5:30 overlaps with 4:00-5:30)
4. **Reports conflicts** with detailed messages

### Conflict Types

The system now detects three types of schedule conflicts:

| Conflict Type | Description | Check Logic |
|--------------|-------------|-------------|
| **room_time_conflict** | Same room booked at same time | Same room + time overlap |
| **section_conflict** | Same subject-section scheduled twice | Same subject + section + time overlap |
| **block_sectioning_conflict** | Students can't attend multiple subjects | Same year/section + **different subjects** + time overlap |

### API Integration

The conflict detection is integrated into:

#### 1. Schedule Creation/Update
```php
// ScheduleController::checkConflict()
$conflicts = $conflictDetector->detectConflicts($schedule, $isEditingExisting);

$hardConflicts = array_filter($conflicts, function($conflict) {
    return $conflict['type'] === 'room_time_conflict' 
        || $conflict['type'] === 'section_conflict'
        || $conflict['type'] === 'block_sectioning_conflict';  // NEW
});
```

#### 2. Conflict Messages
Block sectioning conflicts display detailed information:
```
BLOCK SECTIONING CONFLICT: Year 3 Section B students cannot attend both 
ITS 306 and ITS 307 at the same time (M-T, 4:00 PM - 5:30 PM). 
Faculty: John Doe, Room: Room 201
```

## Usage

### For Schedulers

When creating or editing a schedule:

1. **Fill in schedule details** including:
   - Subject
   - Section
   - Time slots
   - Curriculum subject (for year level detection)

2. **System automatically checks** for conflicts

3. **Block sectioning conflicts appear** as error messages:
   - Prevents saving the schedule
   - Shows which subjects conflict
   - Displays the conflicting time and details

### For Administrators

#### View Conflicts
```php
// Get all conflicted schedules with details
$conflictedSchedules = $conflictDetector->getConflictedSchedulesWithDetails(
    $departmentId,
    $academicYear,
    $semester
);

// Each entry includes:
// - 'schedule': The conflicted schedule
// - 'conflicts': Array of all conflicts
// - 'conflict_count': Total number of conflicts
```

#### Scan and Update
```php
// Scan all schedules and update conflict status
$stats = $conflictDetector->scanAndUpdateAllConflicts($departmentId);

// Returns:
// - 'total_scanned': Number of schedules checked
// - 'conflicts_found': Number with conflicts
// - 'schedules_updated': Number of status changes
```

## Configuration Requirements

### Prerequisites

For block sectioning conflict detection to work:

1. ✅ **Curriculum must be set up** with proper year levels and terms
2. ✅ **Subjects must be linked** to curriculum terms
3. ✅ **Schedules must reference** curriculum subjects (not just subjects)
4. ✅ **Sections must be consistently named** across subjects

### Data Requirements

Each schedule needs:
- `section` - Not null
- `curriculum_subject_id` - Not null (links to curriculum term)
- Valid `day_pattern`, `start_time`, `end_time`
- Active `status`

## Testing

### Manual Testing Scenarios

#### Scenario 1: Create Block Conflict
1. Create ITS 306 Section B for 3rd Year at M-T 4:00-5:30 PM
2. Attempt to create ITS 307 Section B for 3rd Year at M-T 4:00-5:30 PM
3. ✅ **Expected**: System shows block sectioning conflict error

#### Scenario 2: No Conflict - Different Sections
1. Create ITS 306 Section A for 3rd Year at M-T 4:00-5:30 PM
2. Create ITS 307 Section B for 3rd Year at M-T 4:00-5:30 PM
3. ✅ **Expected**: No conflict (different sections)

#### Scenario 3: No Conflict - Different Times
1. Create ITS 306 Section B for 3rd Year at M-T 4:00-5:30 PM
2. Create ITS 307 Section B for 3rd Year at M-T 7:00-8:30 PM
3. ✅ **Expected**: No conflict (different times)

#### Scenario 4: No Conflict - Different Year Levels
1. Create ITS 306 Section B for 3rd Year at M-T 4:00-5:30 PM
2. Create ITS 307 Section B for 4th Year at M-T 4:00-5:30 PM
3. ✅ **Expected**: No conflict (different year levels)

### Unit Testing

Test cases should cover:
- ✅ Same year + same section + different subjects + overlapping time = CONFLICT
- ✅ Different sections = NO CONFLICT
- ✅ Different year levels = NO CONFLICT
- ✅ No time overlap = NO CONFLICT
- ✅ Null curriculum subject = SKIP CHECK

## Troubleshooting

### Issue: Block conflicts not detected

**Possible causes**:
1. Schedule doesn't have `curriculum_subject_id` set
2. Curriculum subject not linked to curriculum term
3. Year level not properly set in curriculum term

**Solution**:
- Verify curriculum setup is complete
- Check that schedules reference curriculum subjects
- Ensure year levels are correctly assigned

### Issue: False positive conflicts

**Possible causes**:
1. Incorrect year level assignment
2. Section naming inconsistency
3. Old inactive schedules not filtered

**Solution**:
- Review curriculum term year levels
- Standardize section naming (A, B, C, etc.)
- Ensure status filter is applied correctly

## Future Enhancements

Potential improvements:

1. **Student-based validation** - Track actual student enrollments for more accurate conflict detection
2. **Cross-semester checking** - Detect conflicts across different semesters if needed
3. **Batch conflict resolution** - Tools to automatically suggest schedule adjustments
4. **Visual conflict display** - Color-coded timetable showing block sectioning conflicts
5. **Conflict prevention** - Smart scheduling assistant that avoids creating conflicts

## Related Documentation

- [Schedule Management Guide](./SCHEDULE_MANAGEMENT.md)
- [Curriculum Setup Guide](./CURRICULUM_SETUP.md)
- [Conflict Resolution Guide](./CONFLICT_RESOLUTION.md)

---

**Last Updated**: January 27, 2026  
**Feature Version**: 1.0.0  
**Status**: ✅ Active
