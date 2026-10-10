<x-layouts.app title="Settings">
    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ route('settings.update') }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="site_name" class="form-label">Site name</label>
                            <input type="text" id="site_name" name="site_name" value="{{ old('site_name', $settings['site_name']) }}" required
                                @class(['form-control', 'is-invalid' => $errors->has('site_name')])>
                            @error('site_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="parcel_matching" class="form-label">Parcel matching</label>
                            <select id="parcel_matching" name="parcel_matching" required
                                @class(['form-select', 'is-invalid' => $errors->has('parcel_matching')])>
                                @foreach ($methods as $method)
                                    <option value="{{ $method->value }}"{{ $method->value === old('parcel_matching', $settings['parcel_matching']) ? ' selected' : '' }}>{{ $method->label() }}</option>
                                @endforeach
                            </select>
                            @error('parcel_matching')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                <div class="form-text">Which parcels a sale uses up first. A single sale can still be matched by hand.</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="first_report_year" class="form-label">First financial year reported</label>
                            <select id="first_report_year" name="first_report_year" required
                                @class(['form-select', 'is-invalid' => $errors->has('first_report_year')])>
                                @foreach ($years as $year)
                                    <option value="{{ $year }}"{{ $year === (int) old('first_report_year', $settings['first_report_year']) ? ' selected' : '' }}>{{ $year - 1 }}–{{ substr($year, -2) }}</option>
                                @endforeach
                            </select>
                            @error('first_report_year')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                <div class="form-text">Earlier years were lodged outside this site and are shown as closed.</div>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-primary">Save</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
