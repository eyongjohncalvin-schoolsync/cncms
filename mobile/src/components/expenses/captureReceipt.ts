import { Alert } from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import type { ReceiptSource } from '../../types/db';

export interface CapturedReceipt {
    uri: string;
    source: ReceiptSource;
    /** When the photo was taken (camera) or picked (gallery), device ISO time. */
    capturedAt: string;
}

/**
 * Takes a receipt photo with the camera, or picks one from the gallery —
 * the gallery path is recorded as source 'gallery' so the office can tell
 * (the server flags reused photos). Returns null when cancelled or when the
 * permission is refused (the user is told how to fix it).
 */
export async function captureReceipt(source: ReceiptSource): Promise<CapturedReceipt | null> {
    if (source === 'camera') {
        const permission = await ImagePicker.requestCameraPermissionsAsync();

        if (!permission.granted) {
            Alert.alert('Camera permission needed', 'Allow camera access in your phone settings to photograph receipts.');

            return null;
        }

        const result = await ImagePicker.launchCameraAsync({ quality: 0.5, allowsEditing: false });

        if (result.canceled || !result.assets?.[0]) {
            return null;
        }

        return { uri: result.assets[0].uri, source: 'camera', capturedAt: new Date().toISOString() };
    }

    const permission = await ImagePicker.requestMediaLibraryPermissionsAsync();

    if (!permission.granted) {
        Alert.alert('Photos permission needed', 'Allow photo access in your phone settings to attach a saved receipt.');

        return null;
    }

    const result = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 0.5, allowsEditing: false });

    if (result.canceled || !result.assets?.[0]) {
        return null;
    }

    return { uri: result.assets[0].uri, source: 'gallery', capturedAt: new Date().toISOString() };
}
