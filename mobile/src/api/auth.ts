import { apiClient } from './client';
import type { LoginResponse, MeResponse } from '../types/api';

export async function login(identifier: string, password: string): Promise<LoginResponse> {
    // Accept either an email or a username in the same field, mirroring
    // AuthController::login()'s email/username duality — we send it as
    // `email` when it looks like one, `username` otherwise.
    const isEmail = identifier.includes('@');

    const { data } = await apiClient.post<LoginResponse>('/auth/login', {
        [isEmail ? 'email' : 'username']: identifier,
        password,
    });

    return data;
}

export async function fetchMe(): Promise<MeResponse> {
    const { data } = await apiClient.get<MeResponse>('/auth/me');

    return data;
}

export async function logout(): Promise<void> {
    await apiClient.post('/auth/logout');
}
